<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\SettlementEvidence;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Events\ReconciliationReversed;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Ledger\ReverseJournalCommand;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Ownership\LegalEntityScope;

final class ReverseReconciliation
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
        private readonly SettlementEvidence $settlementEvidence,
    ) {}

    public function handle(Reconciliation $reconciliation, string $postedOn, string $reason): Reconciliation
    {
        $this->authorizer->authorize('reverse_reconciliation', $reconciliation);
        $this->scope->assertSame((int) $reconciliation->legal_entity_id);

        return $reconciliation->getConnection()->transaction(function () use ($reconciliation, $postedOn, $reason): Reconciliation {
            LegalEntity::query()->lockForUpdate()->findOrFail($reconciliation->getRawOriginal('legal_entity_id'));
            /** @var Reconciliation $reconciliation */
            $reconciliation = Reconciliation::query()->lockForUpdate()->findOrFail($reconciliation->getKey());

            $this->scope->assertSame((int) $reconciliation->legal_entity_id);
            $this->authorizer->authorize('reverse_reconciliation', $reconciliation);

            if ($reconciliation->status !== ReconciliationStatus::Posted) {
                throw new ReconciliationException(__('filament-accounting::errors.reconciliation_not_posted'));
            }

            $actor = $this->actors->resolve();
            $key = 'reverse-reconciliation:'.$reconciliation->getKey();

            if (! $reconciliation->journal_entry_id) {
                throw new ReconciliationException(__('filament-accounting::errors.reconciliation_not_posted'));
            }

            $reversalEntry = $this->ledger->reverse(new ReverseJournalCommand(
                journalEntryId: (int) $reconciliation->journal_entry_id,
                postedOn: $postedOn,
                reason: $reason,
                idempotencyKey: $key,
                postedByType: $actor?->getMorphClass(),
                postedById: $actor ? (string) $actor->getKey() : null,
            ));

            $settlements = Settlement::query()
                ->where('journal_entry_id', $reconciliation->journal_entry_id)
                ->where('is_reversed', false)
                ->lockForUpdate()
                ->get();

            foreach ($settlements as $settlement) {
                $reversalAmount = -1 * (int) $settlement->amount_minor;
                $reversing = new Settlement;
                $reversing->fill([
                    'legal_entity_id' => $settlement->legal_entity_id,
                    'open_item_id' => $settlement->open_item_id,
                    'journal_entry_id' => $reversalEntry->getKey(),
                    'amount_minor' => $reversalAmount,
                    'currency' => $settlement->currency,
                    'is_reversed' => true,
                    'reverses_id' => $settlement->getKey(),
                    'evidence' => $this->settlementEvidence->forReversal($settlement, $reversalAmount),
                ]);
                $reversing->save();

                $settlement->is_reversed = true;
                $settlement->save();
            }

            $reversingRec = new Reconciliation;
            $reversingRec->fill([
                'legal_entity_id' => $reconciliation->legal_entity_id,
                'statement_line_id' => $reconciliation->statement_line_id,
                'status' => ReconciliationStatus::Reversed,
                'journal_entry_id' => $reversalEntry->getKey(),
                'version' => $reconciliation->version + 1,
                'reverses_id' => $reconciliation->getKey(),
                'idempotency_key' => $key,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor ? (string) $actor->getKey() : null,
                'finalized_at' => now(),
                'reason' => $reason,
            ]);
            $reversingRec->save();

            $reconciliation->status = ReconciliationStatus::Reversed;
            $reconciliation->save();

            $entity = LegalEntity::query()->findOrFail($reconciliation->legal_entity_id);
            $this->audit->log($entity, 'reconciliation.reversed', $reconciliation, [
                'reason' => $reason,
            ], $reason);

            $reconciliation->getConnection()->afterCommit(fn () => ReconciliationReversed::dispatch($reconciliation->fresh()));

            return $reconciliation->fresh() ?? $reconciliation;
        });
    }
}

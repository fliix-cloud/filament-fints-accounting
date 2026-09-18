<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\SettlementEvidence;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Events\ReconciliationFinalized;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Models\TaxRuleVersion;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Reconciliation\StoreReconciliationLearningRules;
use FilamentAccounting\Support\LineMoneyCalculator;

final class FinalizeReconciliation
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
        private readonly StoreReconciliationLearningRules $learningRules,
        private readonly SettlementEvidence $settlementEvidence,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $splits
     */
    public function handle(BankStatementLine $line, array $splits, ?string $reason = null, ?string $idempotencyKey = null): Reconciliation
    {
        $this->authorizer->authorize('finalize_reconciliation', $line);
        $this->scope->assertSame((int) $line->legal_entity_id);

        return $line->getConnection()->transaction(function () use ($line, $splits, $reason, $idempotencyKey): Reconciliation {
            LegalEntity::query()->lockForUpdate()->findOrFail($line->getRawOriginal('legal_entity_id'));
            $line = BankStatementLine::query()->lockForUpdate()->with('bankAccount')->findOrFail($line->getKey());
            $this->scope->assertSame((int) $line->legal_entity_id);
            $this->authorizer->authorize('finalize_reconciliation', $line);
            if (! $line->bankAccount->is_active) {
                throw new ReconciliationException(__('filament-accounting::errors.bank_account_inactive'));
            }
            if ($idempotencyKey !== null) {
                $existing = Reconciliation::query()
                    ->where('legal_entity_id', $line->legal_entity_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing?->status === ReconciliationStatus::Posted) {
                    return $existing;
                }

                if ($existing instanceof Reconciliation) {
                    throw new ReconciliationException(__('filament-accounting::errors.idempotency_key_reused'));
                }
            }

            $posted = Reconciliation::query()
                ->where('statement_line_id', $line->getKey())
                ->where('status', ReconciliationStatus::Posted)
                ->lockForUpdate()
                ->first();

            if ($posted instanceof Reconciliation) {
                if ($idempotencyKey === null) {
                    return $posted;
                }

                throw new ReconciliationException(__('filament-accounting::errors.already_reconciled'));
            }

            if ($line->source_status !== StatementLineStatus::Booked) {
                throw new ReconciliationException(__('filament-accounting::errors.pending_cannot_finalize'));
            }

            $allocations = $this->normalizeAndValidateAllocations($line, $splits);
            $sum = array_sum(array_column($allocations, 'amount_minor'));

            if ($sum !== (int) $line->amount_minor) {
                throw new ReconciliationException(__('filament-accounting::errors.reconciliation_imbalance'));
            }

            $actor = $this->actors->resolve();
            $entity = LegalEntity::query()->findOrFail($line->legal_entity_id);
            $version = ((int) Reconciliation::query()
                ->where('statement_line_id', $line->getKey())
                ->max('version')) + 1;
            $key = $idempotencyKey ?: 'reconciliation:'.$line->getKey().':v'.$version;

            $reconciliation = new Reconciliation;
            $reconciliation->fill([
                'legal_entity_id' => $line->legal_entity_id,
                'statement_line_id' => $line->getKey(),
                'status' => ReconciliationStatus::Ready,
                'version' => $version,
                'idempotency_key' => $key,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor ? (string) $actor->getKey() : null,
                'reason' => $reason,
                'match_meta' => $this->matchMeta($line, $allocations),
            ]);
            $reconciliation->save();

            foreach ($allocations as $input) {
                $split = new ReconciliationSplit;
                $split->fill([
                    'reconciliation_id' => $reconciliation->getKey(),
                    'purpose' => $input['purpose'],
                    'amount_minor' => $input['amount_minor'],
                    'currency' => $line->currency,
                    'open_item_id' => $input['open_item_id'] ?? null,
                    'posting_rule_version_id' => $input['posting_rule_version_id'] ?? null,
                    'ledger_account_id' => $input['ledger_account_id'] ?? null,
                    'tax_rule_version_id' => $input['tax_rule_version_id'] ?? null,
                    'reason' => $input['reason'] ?? null,
                ]);
                $split->save();
            }

            $journalLines = $this->buildJournalLines($entity, $line, $reconciliation->fresh([
                'splits.openItem',
                'splits.postingRuleVersion',
                'splits.ledgerAccount',
                'splits.taxRuleVersion.taxCode',
            ]) ?? $reconciliation);
            $entry = $this->ledger->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: ($line->booking_date ?? now())->toDateString(),
                sourceType: 'reconciliation',
                sourceId: (string) $reconciliation->getKey(),
                currency: (string) $line->currency,
                baseCurrency: (string) $entity->base_currency,
                lines: $journalLines,
                description: $line->purpose,
                postingRuleVersionId: $this->singlePostingRuleVersionId($reconciliation),
                idempotencyKey: 'journal:'.$key,
                postedByType: $actor?->getMorphClass(),
                postedById: $actor ? (string) $actor->getKey() : null,
            ));

            foreach ($reconciliation->splits()->get() as $split) {
                if (! $split instanceof ReconciliationSplit) {
                    continue;
                }
                if ($split->purpose === SplitPurpose::SettleOpenItem && $split->open_item_id) {
                    $item = OpenItem::query()->lockForUpdate()->findOrFail($split->open_item_id);
                    if ((int) $item->legal_entity_id !== (int) $line->legal_entity_id) {
                        throw new ReconciliationException(__('filament-accounting::errors.entity_mismatch'));
                    }

                    $amount = abs((int) $split->amount_minor);
                    $remaining = abs($item->remainingMinor());
                    if ($amount > $remaining) {
                        throw new ReconciliationException(__('filament-accounting::errors.settlement_exceeds_remaining'));
                    }

                    $remainingBefore = $item->remainingMinor();
                    $settlement = new Settlement;
                    $settlement->fill([
                        'legal_entity_id' => $line->legal_entity_id,
                        'open_item_id' => $item->getKey(),
                        'journal_entry_id' => $entry->getKey(),
                        'amount_minor' => $amount,
                        'currency' => $line->currency,
                        'is_reversed' => false,
                        'evidence' => $this->settlementEvidence->capture(
                            $item,
                            $amount,
                            (string) $line->currency,
                            $line,
                            $remainingBefore,
                        ),
                    ]);
                    $settlement->save();
                }
            }

            $reconciliation->status = ReconciliationStatus::Posted;
            $reconciliation->journal_entry_id = $entry->getKey();
            $reconciliation->finalized_at = now();
            $reconciliation->save();
            $this->learningRules->handle($reconciliation, $line);

            $this->audit->log($entity, 'reconciliation.finalized', $reconciliation, [
                'statement_line_id' => $line->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            $line->getConnection()->afterCommit(fn () => ReconciliationFinalized::dispatch($reconciliation->fresh(['splits', 'journalEntry'])));

            return $reconciliation->fresh(['splits', 'journalEntry']) ?? $reconciliation;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     * @return array<string, mixed>
     */
    private function matchMeta(BankStatementLine $line, array $allocations): array
    {
        $meta = [
            'mode' => count($allocations) === 1 ? 'direct' : 'split',
            'allocation_count' => count($allocations),
            'decision_sources' => array_values(array_unique(array_map(
                fn (array $allocation): string => (string) ($allocation['selection_source'] ?? 'manual'),
                $allocations,
            ))),
        ];

        if (count($allocations) !== 1) {
            return $meta;
        }

        $allocation = $allocations[0];
        $meta['selection_source'] = (string) ($allocation['selection_source'] ?? 'manual');
        if (isset($allocation['suggestion_score'])) {
            $meta['suggestion_score'] = (int) $allocation['suggestion_score'];
        }
        $purpose = $allocation['purpose'] ?? null;
        if (! $purpose instanceof SplitPurpose) {
            $purpose = SplitPurpose::tryFrom((string) $purpose);
        }
        if ($purpose !== SplitPurpose::SettleOpenItem || empty($allocation['open_item_id'])) {
            return $meta;
        }

        $item = OpenItem::query()->find($allocation['open_item_id']);
        if (! $item instanceof OpenItem) {
            return $meta;
        }

        $assigned = abs((int) $line->amount_minor);
        $remaining = abs($item->remainingMinor());
        $meta['amount_match'] = $assigned === $remaining;
        $meta['assigned_amount_minor'] = (int) $line->amount_minor;
        $meta['open_item_remaining_minor'] = $item->remainingMinor();

        return $meta;
    }

    private function singlePostingRuleVersionId(Reconciliation $reconciliation): ?int
    {
        if ($reconciliation->splits->count() !== 1) {
            return null;
        }

        $split = $reconciliation->splits->first();

        return $split?->purpose === SplitPurpose::PostingRule && $split->posting_rule_version_id
            ? (int) $split->posting_rule_version_id
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     * @return list<array<string, mixed>>
     */
    private function normalizeAndValidateAllocations(BankStatementLine $line, array $allocations): array
    {
        if ($allocations === []) {
            throw new ReconciliationException(__('filament-accounting::errors.reconciliation_needs_allocation'));
        }

        $normalized = [];
        $openItemTargets = [];

        foreach ($allocations as $input) {
            $purpose = SplitPurpose::tryFrom((string) ($input['purpose'] ?? ''));
            if (! $purpose instanceof SplitPurpose) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_purpose'));
            }

            $rawAmount = $input['amount_minor'] ?? null;
            if (! is_int($rawAmount) && (! is_string($rawAmount) || preg_match('/^-?\d+$/', $rawAmount) !== 1)) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_amount'));
            }

            $amount = (int) $rawAmount;
            if ($amount === 0) {
                throw new ReconciliationException(__('filament-accounting::errors.zero_allocation'));
            }

            if (($line->amount_minor > 0 && $amount < 0) || ($line->amount_minor < 0 && $amount > 0)) {
                throw new ReconciliationException(__('filament-accounting::errors.allocation_sign_mismatch'));
            }

            $openItemId = $this->targetId($input['open_item_id'] ?? null);
            $postingRuleVersionId = $this->targetId($input['posting_rule_version_id'] ?? null);
            $ledgerAccountId = $this->targetId($input['ledger_account_id'] ?? null);
            $taxRuleVersionId = $this->targetId($input['tax_rule_version_id'] ?? null);
            $reason = filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null;

            $this->validateTargetShape($purpose, $openItemId, $postingRuleVersionId, $ledgerAccountId, $reason);
            $this->validateTargetRecords(
                $line,
                $purpose,
                $amount,
                $openItemId,
                $postingRuleVersionId,
                $ledgerAccountId,
            );
            $this->validateTaxRuleSelection($line, $purpose, $taxRuleVersionId);

            if ($purpose === SplitPurpose::SettleOpenItem && $openItemId !== null) {
                if (in_array($openItemId, $openItemTargets, true)) {
                    throw new ReconciliationException(__('filament-accounting::errors.duplicate_open_item_allocation'));
                }

                $openItemTargets[] = $openItemId;
            }

            $selectionSource = in_array(($input['selection_source'] ?? null), ['manual', 'suggestion_confirmed'], true)
                ? (string) $input['selection_source']
                : 'manual';
            $suggestionScore = isset($input['suggestion_score']) && is_numeric($input['suggestion_score'])
                ? max(0, (int) $input['suggestion_score'])
                : null;

            $normalized[] = [
                'purpose' => $purpose,
                'amount_minor' => $amount,
                'open_item_id' => $openItemId,
                'posting_rule_version_id' => $postingRuleVersionId,
                'ledger_account_id' => $ledgerAccountId,
                'tax_rule_version_id' => $taxRuleVersionId,
                'reason' => $reason,
                'selection_source' => $selectionSource,
                'suggestion_score' => $suggestionScore,
            ];
        }

        return $normalized;
    }

    private function targetId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((! is_int($value) && (! is_string($value) || preg_match('/^\d+$/', $value) !== 1)) || (int) $value < 1) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
        }

        return (int) $value;
    }

    private function validateTargetShape(
        SplitPurpose $purpose,
        ?int $openItemId,
        ?int $postingRuleVersionId,
        ?int $ledgerAccountId,
        ?string $reason,
    ): void {
        $valid = match ($purpose) {
            SplitPurpose::SettleOpenItem => $openItemId !== null
                && $postingRuleVersionId === null
                && $ledgerAccountId === null,
            SplitPurpose::PostingRule => $openItemId === null
                && $postingRuleVersionId !== null
                && $ledgerAccountId === null,
            SplitPurpose::LedgerAccount, SplitPurpose::Transfer => $openItemId === null
                && $postingRuleVersionId === null
                && $ledgerAccountId !== null,
            SplitPurpose::BankFee, SplitPurpose::Rounding, SplitPurpose::Suspense => $openItemId === null
                && $postingRuleVersionId === null
                && $ledgerAccountId === null,
        };

        if (! $valid) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
        }

        if ($purpose === SplitPurpose::Suspense && $reason === null) {
            throw new ReconciliationException(__('filament-accounting::errors.suspense_reason_required'));
        }
    }

    private function validateTargetRecords(
        BankStatementLine $line,
        SplitPurpose $purpose,
        int $amount,
        ?int $openItemId,
        ?int $postingRuleVersionId,
        ?int $ledgerAccountId,
    ): void {
        if ($purpose === SplitPurpose::SettleOpenItem && $openItemId !== null) {
            $item = OpenItem::query()
                ->where('legal_entity_id', $line->legal_entity_id)
                ->where('is_reversed', false)
                ->lockForUpdate()
                ->find($openItemId);

            if (! $item instanceof OpenItem) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
            }

            if (strtoupper($item->currency) !== strtoupper($line->currency)) {
                throw new ReconciliationException(__('filament-accounting::errors.allocation_currency_mismatch'));
            }

            $expectedIncoming = $item->remainingMinor() > 0
                ? $item->kind === OpenItemKind::Receivable
                : $item->kind === OpenItemKind::Payable;
            if ($expectedIncoming !== ($amount > 0)) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
            }

            if (abs($amount) > abs($item->remainingMinor())) {
                throw new ReconciliationException(__('filament-accounting::errors.settlement_exceeds_remaining'));
            }
        }

        if ($purpose === SplitPurpose::PostingRule && $postingRuleVersionId !== null) {
            $date = ($line->booking_date ?? now())->toDateString();
            $version = PostingRuleVersion::query()
                ->whereKey($postingRuleVersionId)
                ->whereDate('valid_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
                })
                ->whereHas('postingRule', function ($query) use ($line): void {
                    $query->where('legal_entity_id', $line->legal_entity_id)->where('is_active', true);
                })
                ->lockForUpdate()
                ->first();

            if (! $version instanceof PostingRuleVersion) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
            }
        }

        if (in_array($purpose, [SplitPurpose::LedgerAccount, SplitPurpose::Transfer], true) && $ledgerAccountId !== null) {
            $account = LedgerAccount::query()
                ->where('legal_entity_id', $line->legal_entity_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($ledgerAccountId);

            if (! $account instanceof LedgerAccount) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
            }
        }
    }

    private function validateTaxRuleSelection(BankStatementLine $line, SplitPurpose $purpose, ?int $taxRuleVersionId): void
    {
        if ($taxRuleVersionId === null) {
            return;
        }

        if (! in_array($purpose, [SplitPurpose::PostingRule, SplitPurpose::LedgerAccount], true)) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_tax_rule'));
        }

        $date = ($line->booking_date ?? now())->toDateString();
        $direction = $line->isIncoming() ? 'incoming' : 'outgoing';
        $valid = TaxRuleVersion::query()
            ->whereKey($taxRuleVersionId)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->whereHas('taxCode', function ($query) use ($line, $direction): void {
                $query->where('legal_entity_id', $line->legal_entity_id)
                    ->where('is_active', true)
                    ->where(function ($query) use ($direction): void {
                        $query->whereNull('direction')->orWhere('direction', 'both')->orWhere('direction', $direction);
                    });
            })
            ->exists();

        if (! $valid) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_tax_rule'));
        }
    }

    /**
     * @return list<JournalLineDraft>
     */
    private function buildJournalLines(LegalEntity $entity, BankStatementLine $line, Reconciliation $reconciliation): array
    {
        $currency = (string) $line->currency;
        $bankAccountId = (int) $line->bankAccount->ledger_account_id;
        $amount = (int) $line->amount_minor;
        $drafts = [];

        if ($amount > 0) {
            $drafts[] = JournalLineDraft::debit($bankAccountId, $amount, $currency, $line->purpose);
        } elseif ($amount < 0) {
            $drafts[] = JournalLineDraft::credit($bankAccountId, abs($amount), $currency, $line->purpose);
        }

        foreach ($reconciliation->splits as $split) {
            $splitAmount = abs((int) $split->amount_minor);
            if ($splitAmount === 0) {
                continue;
            }

            if ($split->purpose === SplitPurpose::PostingRule
                || ($split->purpose === SplitPurpose::LedgerAccount && $split->tax_rule_version_id !== null)) {
                array_push($drafts, ...$this->categoryJournalLines($entity, $line, $split));

                continue;
            }

            $accountId = $this->counterpartAccount($entity, $line, $split);
            $incoming = (int) $split->amount_minor > 0;

            if ($incoming) {
                $drafts[] = JournalLineDraft::credit($accountId, $splitAmount, $currency, $split->reason);
            } else {
                $drafts[] = JournalLineDraft::debit($accountId, $splitAmount, $currency, $split->reason);
            }
        }

        return $drafts;
    }

    /** @return list<JournalLineDraft> */
    private function categoryJournalLines(
        LegalEntity $entity,
        BankStatementLine $line,
        ReconciliationSplit $split,
    ): array {
        $version = $split->postingRuleVersion;
        if ($split->purpose === SplitPurpose::PostingRule && ! $version instanceof PostingRuleVersion) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
        }

        $currency = (string) $line->currency;
        $gross = abs((int) $split->amount_minor);
        $incoming = (int) $split->amount_minor > 0;
        $counterpartAccountId = $this->counterpartAccount($entity, $line, $split);
        $taxRule = $split->taxRuleVersion;
        $selectedTaxCode = $taxRule instanceof TaxRuleVersion ? $taxRule->taxCode : null;
        $taxCodeValue = $selectedTaxCode instanceof TaxCode ? (string) $selectedTaxCode->code : null;

        if (! $taxRule instanceof TaxRuleVersion && $version instanceof PostingRuleVersion && filled($version->tax_code)) {
            $taxCodeValue = (string) $version->tax_code;
            $date = ($line->booking_date ?? now())->toDateString();
            $taxRule = TaxCode::query()
                ->where('legal_entity_id', $entity->getKey())
                ->where('code', $taxCodeValue)
                ->where('is_active', true)
                ->first()?->versionOn($date);
        }

        if ($taxCodeValue === null && ! $taxRule instanceof TaxRuleVersion) {
            return [$incoming
                ? JournalLineDraft::credit($counterpartAccountId, $gross, $currency, $split->reason)
                : JournalLineDraft::debit($counterpartAccountId, $gross, $currency, $split->reason)];
        }

        if (! $taxRule instanceof TaxRuleVersion) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_tax_rule'));
        }

        $net = LineMoneyCalculator::netMinorFromGross($gross, (int) $taxRule->rate_bp);
        $tax = $gross - $net;
        $lines = [$incoming
            ? JournalLineDraft::credit($counterpartAccountId, $net, $currency, $split->reason, $taxCodeValue, (int) $taxRule->getKey())
            : JournalLineDraft::debit($counterpartAccountId, $net, $currency, $split->reason, $taxCodeValue, (int) $taxRule->getKey())];

        if ($tax !== 0) {
            $taxAccountId = $this->roleAccount(
                $entity,
                $incoming ? AccountRole::OutputTax : AccountRole::InputTax,
            );
            $lines[] = $incoming
                ? JournalLineDraft::credit($taxAccountId, $tax, $currency, $split->reason, $taxCodeValue, (int) $taxRule->getKey())
                : JournalLineDraft::debit($taxAccountId, $tax, $currency, $split->reason, $taxCodeValue, (int) $taxRule->getKey());
        }

        return $lines;
    }

    private function counterpartAccount(LegalEntity $entity, BankStatementLine $line, ReconciliationSplit $split): int
    {
        if ($split->ledger_account_id) {
            return (int) $split->ledger_account_id;
        }

        if ($split->purpose === SplitPurpose::SettleOpenItem && $split->openItem instanceof OpenItem) {
            $role = $split->openItem->kind === OpenItemKind::Receivable
                ? AccountRole::Receivable
                : AccountRole::Payable;

            return $this->roleAccount($entity, $role);
        }

        if ($split->purpose === SplitPurpose::BankFee) {
            return $this->roleAccount($entity, AccountRole::Expense);
        }

        if ($split->purpose === SplitPurpose::Suspense) {
            return $this->roleAccount($entity, AccountRole::Suspense);
        }

        if ($split->purpose === SplitPurpose::Rounding) {
            return $this->roleAccount($entity, AccountRole::Rounding);
        }

        if ($split->posting_rule_version_id && $split->postingRuleVersion) {
            $mappings = $split->postingRuleVersion->account_mappings ?? [];
            $role = $mappings['counterpart'] ?? $mappings['expense'] ?? $mappings['revenue'] ?? null;
            if (is_string($role)) {
                return $this->roleAccount($entity, AccountRole::from($role));
            }
            if (isset($mappings['ledger_account_id'])) {
                return $this->validatedLedgerAccountId($entity, (int) $mappings['ledger_account_id']);
            }
        }

        return $this->roleAccount($entity, $line->isIncoming() ? AccountRole::Revenue : AccountRole::Expense);
    }

    private function roleAccount(LegalEntity $entity, AccountRole $role): int
    {
        $assignment = AccountRoleAssignment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('role', $role->value)
            ->first();

        if (! $assignment instanceof AccountRoleAssignment) {
            throw new ReconciliationException(__('filament-accounting::errors.missing_account_role', ['role' => $role->value]));
        }

        return $this->validatedLedgerAccountId($entity, (int) $assignment->ledger_account_id);
    }

    private function validatedLedgerAccountId(LegalEntity $entity, int $ledgerAccountId): int
    {
        $exists = LedgerAccount::query()
            ->whereKey($ledgerAccountId)
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_target'));
        }

        return $ledgerAccountId;
    }
}

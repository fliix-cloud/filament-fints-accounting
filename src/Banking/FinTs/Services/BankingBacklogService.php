<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Operator-facing inventory of unfinished banking sync and intake work.
 *
 * Catch-up markers and sync-run statuses already exist; this service is the
 * single place that turns them into an explicit backlog so unfinished work
 * cannot silently accumulate.
 */
class BankingBacklogService
{
    public const ACK_EVIDENCE_KEY = 'operator_acknowledged_at';

    public const STUCK_RUNNING_HOURS = 2;

    /**
     * @return array{
     *     catch_up_accounts: int,
     *     open_sync_runs: int,
     *     pending_intakes: int,
     *     needs_review_lines: int,
     *     has_open_backlog: bool,
     *     items: list<array<string, mixed>>
     * }
     */
    public function summarize(?int $legalEntityId = null): array
    {
        $catchUp = $this->catchUpAccountsQuery($legalEntityId)->count();
        $syncRuns = $this->openSyncRunsQuery($legalEntityId)->count();
        $intakes = $this->pendingIntakesQuery($legalEntityId)->count();
        $review = $this->needsReviewLinesQuery($legalEntityId)->count();

        return [
            'catch_up_accounts' => $catchUp,
            'open_sync_runs' => $syncRuns,
            'pending_intakes' => $intakes,
            'needs_review_lines' => $review,
            'has_open_backlog' => ($catchUp + $syncRuns + $intakes + $review) > 0,
            'items' => $this->items($legalEntityId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(?int $legalEntityId = null): array
    {
        $items = [];

        foreach ($this->catchUpAccountsQuery($legalEntityId)->get() as $account) {
            /** @var AccountingBankAccount $account */
            $remainingDays = $account->catch_up_from instanceof \DateTimeInterface
                ? (int) $account->catch_up_from->diffInDays(Carbon::today())
                : 0;
            $chunkDays = max(1, (int) config('filament-accounting.banking.fints.sync.max_range_days', 90));

            $items[] = [
                'kind' => 'catch_up',
                'severity' => 'open',
                'account_id' => (int) $account->getKey(),
                'account_uuid' => $account->uuid,
                'account_name' => $account->display_name,
                'catch_up_from' => $account->catch_up_from?->toDateString(),
                'remaining_days' => $remainingDays,
                'approx_chunks' => max(1, (int) ceil($remainingDays / $chunkDays)),
                'action' => 'continue',
            ];
        }

        foreach ($this->openSyncRunsQuery($legalEntityId)->with('account')->get() as $run) {
            /** @var BankSyncRun $run */
            $items[] = [
                'kind' => 'sync_run',
                'severity' => $this->syncRunSeverity($run),
                'sync_run_id' => (int) $run->getKey(),
                'sync_run_uuid' => $run->uuid,
                'account_id' => $run->accounting_bank_account_id,
                'account_name' => $run->account?->display_name,
                'status' => $run->status->value,
                'type' => $run->type->value,
                'error_code' => $run->error_code,
                'error_message' => $run->error_message,
                'requested_from_date' => $run->requested_from_date?->toDateString(),
                'evidence_status' => is_array($run->reconciliation_evidence)
                    ? ($run->reconciliation_evidence['status'] ?? null)
                    : null,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'action' => $this->syncRunAction($run),
            ];
        }

        foreach ($this->pendingIntakesQuery($legalEntityId)->limit(200)->get() as $intake) {
            /** @var PurchaseInvoiceIntake $intake */
            $items[] = [
                'kind' => 'intake',
                'severity' => $intake->status === 'blocked' || $intake->status === 'failed' ? 'failed' : 'open',
                'intake_id' => (int) $intake->getKey(),
                'intake_uuid' => $intake->uuid,
                'status' => $intake->status,
                'last_error' => $intake->last_error,
                'created_at' => $intake->created_at?->toIso8601String(),
                'action' => 'review',
            ];
        }

        foreach ($this->needsReviewLinesQuery($legalEntityId)->limit(200)->get() as $line) {
            /** @var BankStatementLine $line */
            $items[] = [
                'kind' => 'statement_review',
                'severity' => 'open',
                'statement_line_id' => (int) $line->getKey(),
                'statement_line_uuid' => $line->uuid,
                'bank_account_id' => $line->bank_account_id,
                'review_reason' => $line->review_reason,
                'booking_date' => $line->booking_date?->toDateString(),
                'action' => 'review',
            ];
        }

        return $items;
    }

    /**
     * Acknowledge that an operator has seen a failed / attention / mismatched
     * sync run. Does not clear catch-up markers or invent completeness.
     */
    public function acknowledgeSyncRun(BankSyncRun $run, ?string $note = null): BankSyncRun
    {
        if (! in_array($run->status, [SyncStatus::Failed, SyncStatus::RequiresAttention, SyncStatus::Completed], true)) {
            throw new \InvalidArgumentException('Only failed, attention, or completed (evidence gap) runs can be acknowledged.');
        }

        if ($run->status === SyncStatus::Completed) {
            $evidenceStatus = is_array($run->reconciliation_evidence)
                ? ($run->reconciliation_evidence['status'] ?? null)
                : null;
            if (! in_array($evidenceStatus, ['mismatched', 'unavailable'], true) && $run->requested_from_date === null) {
                throw new \InvalidArgumentException('Completed runs without an evidence or coverage gap cannot be acknowledged as backlog.');
            }
        }

        $evidence = is_array($run->reconciliation_evidence) ? $run->reconciliation_evidence : [];
        $evidence[self::ACK_EVIDENCE_KEY] = now()->toIso8601String();
        if ($note !== null && $note !== '') {
            $evidence['operator_acknowledged_note'] = $note;
        }

        $run->reconciliation_evidence = $evidence;
        $run->save();

        return $run->refresh();
    }

    public function isAcknowledged(BankSyncRun $run): bool
    {
        $evidence = $run->reconciliation_evidence;

        return is_array($evidence) && isset($evidence[self::ACK_EVIDENCE_KEY]);
    }

    /**
     * @return Collection<int, AccountingBankAccount>
     */
    public function catchUpAccounts(?int $legalEntityId = null): Collection
    {
        return $this->catchUpAccountsQuery($legalEntityId)->get();
    }

    /**
     * Continue catch-up for every account that still has a marker.
     *
     * @return list<array{account_id: int, complete: bool, chunks: int, stopped_for: string}>
     */
    public function continueCatchUp(?int $legalEntityId = null): array
    {
        $transactions = app(TransactionSyncService::class);
        $results = [];

        foreach ($this->catchUpAccountsQuery($legalEntityId)->get() as $account) {
            /** @var AccountingBankAccount $account */
            $result = $transactions->drainCatchUp($account);
            $results[] = [
                'account_id' => (int) $account->getKey(),
                'complete' => (bool) $result['complete'],
                'chunks' => (int) $result['chunks'],
                'stopped_for' => (string) $result['stopped_for'],
            ];
        }

        return $results;
    }

    /** @return Builder<AccountingBankAccount> */
    public function catchUpAccountsQuery(?int $legalEntityId = null): Builder
    {
        $query = AccountingBankAccount::query()
            ->whereNotNull('catch_up_from')
            ->where('is_active', true)
            ->where('is_enabled', true)
            ->where('is_available', true)
            ->orderBy('catch_up_from');

        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        return $query;
    }

    /** @return Builder<BankSyncRun> */
    public function openSyncRunsQuery(?int $legalEntityId = null): Builder
    {
        $stuckBefore = now()->subHours(self::STUCK_RUNNING_HOURS);
        $ackKey = self::ACK_EVIDENCE_KEY;

        $query = BankSyncRun::query()
            ->where(function (Builder $builder) use ($stuckBefore): void {
                $builder
                    ->whereIn('status', [SyncStatus::Failed->value, SyncStatus::RequiresAttention->value])
                    ->orWhere(function (Builder $running) use ($stuckBefore): void {
                        $running->where('status', SyncStatus::Running->value)
                            ->where('started_at', '<=', $stuckBefore);
                    })
                    ->orWhere(function (Builder $completed): void {
                        $completed->where('status', SyncStatus::Completed->value)
                            ->where(function (Builder $gap): void {
                                $gap->whereNotNull('requested_from_date')
                                    ->orWhere('reconciliation_evidence->status', 'mismatched')
                                    ->orWhere('reconciliation_evidence->status', 'unavailable');
                            });
                    });
            })
            // Acknowledged runs drop out of the open backlog listing.
            ->where(function (Builder $builder) use ($ackKey): void {
                $builder->whereNull('reconciliation_evidence')
                    ->orWhereNull("reconciliation_evidence->{{$ackKey}}");
            })
            ->orderByDesc('id');

        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        return $query;
    }

    /** @return Builder<PurchaseInvoiceIntake> */
    public function pendingIntakesQuery(?int $legalEntityId = null): Builder
    {
        $query = PurchaseInvoiceIntake::query()
            ->whereIn('status', ['pending', 'failed', 'blocked'])
            ->orderByDesc('id');

        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        return $query;
    }

    /** @return Builder<BankStatementLine> */
    public function needsReviewLinesQuery(?int $legalEntityId = null): Builder
    {
        $query = BankStatementLine::query()
            ->where('needs_review', true)
            ->orderByDesc('id');

        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        return $query;
    }

    private function syncRunSeverity(BankSyncRun $run): string
    {
        return match ($run->status) {
            SyncStatus::Failed => 'failed',
            SyncStatus::RequiresAttention => 'attention',
            SyncStatus::Running => 'stuck',
            default => 'open',
        };
    }

    private function syncRunAction(BankSyncRun $run): string
    {
        if ($run->status === SyncStatus::RequiresAttention || $run->status === SyncStatus::Failed) {
            return 'continue_or_ack';
        }

        if ($run->status === SyncStatus::Running) {
            return 'investigate';
        }

        return 'ack';
    }
}

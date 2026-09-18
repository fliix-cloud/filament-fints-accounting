<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BalanceSyncService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncCommand extends Command
{
    protected $signature = 'filament-accounting:sync-bank
        {--connection= : Bank connection UUID}
        {--account= : Bank account UUID}
        {--accounts : Sync accounts}
        {--balances : Sync balances}
        {--transactions : Sync transactions}
        {--from= : Statement start date Y-m-d}
        {--to= : Statement end date Y-m-d}
        {--drain : Keep syncing each account until catch-up is cleared (default: config)}
        {--no-drain : Sync only one transaction chunk per account}';

    protected $description = 'Synchronize FinTS accounts, balances, or transactions without interactive payments';

    public function handle(
        AccountSyncService $accounts,
        BalanceSyncService $balances,
        TransactionSyncService $transactions,
    ): int {
        if (! ProductRegistration::isConfigured()) {
            $this->error(__('filament-accounting::banking/fints/notifications.product_id_missing'));

            return self::FAILURE;
        }

        $doAccounts = $this->option('accounts') || (! $this->option('balances') && ! $this->option('transactions'));
        $doBalances = (bool) $this->option('balances');
        $doTransactions = (bool) $this->option('transactions');

        $query = BankConnection::query();
        if ($uuid = $this->option('connection')) {
            $query->where('uuid', $uuid);
        }

        $requestedFrom = $this->option('from');

        foreach ($query->get() as $connection) {
            if ($doAccounts) {
                $outcome = $accounts->sync($connection);
                if ($outcome->requiresUser()) {
                    $this->warn("Connection {$connection->uuid} needs SCA attention.");
                }
            }

            $accountQuery = $connection->accounts()
                ->where('is_available', true)
                ->where('is_enabled', true);
            if ($accountUuid = $this->option('account')) {
                $accountQuery->where('uuid', $accountUuid);
            }

            foreach ($accountQuery->get() as $account) {
                if (! $account instanceof BankAccount) {
                    continue;
                }
                if ($doBalances) {
                    $balances->sync($account);
                }

                if ($doTransactions) {
                    $from = $requestedFrom ? Carbon::parse($requestedFrom) : null;
                    $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;
                    $drain = $this->shouldDrain($from, $to);
                    if ($drain) {
                        $result = $transactions->drainCatchUp($account);
                        $this->reportDrain((int) $account->getKey(), $result);
                        $this->warnIfEvidenceGap((int) $account->getKey());
                    } else {
                        $transactions->sync($account, $from, $to);
                        $this->warnIfTruncated((int) $account->getKey());
                        $this->warnIfEvidenceGap((int) $account->getKey());
                    }
                }
            }
        }

        $this->info('Synchronization finished.');

        return self::SUCCESS;
    }

    private function shouldDrain(mixed $from, mixed $to): bool
    {
        if ($from !== null || $to !== null) {
            return false;
        }

        if ($this->option('no-drain')) {
            return false;
        }

        if ($this->option('drain')) {
            return true;
        }

        return (bool) config('filament-accounting.banking.fints.sync.auto_drain', true);
    }

    /** @param array{outcome: mixed, chunks: int, complete: bool, stopped_for: string} $result */
    private function reportDrain(int $accountId, array $result): void
    {
        if ($result['complete']) {
            $this->info(sprintf(
                'Account %d: catch-up drained in %d chunk(s).',
                $accountId,
                $result['chunks'],
            ));

            return;
        }

        if ($result['stopped_for'] === 'sca') {
            $this->warn(sprintf(
                'Account %d: catch-up paused after %d chunk(s); strong customer authentication is required.',
                $accountId,
                $result['chunks'],
            ));

            return;
        }

        if ($result['stopped_for'] === 'concurrent') {
            $this->warn(sprintf(
                'Account %d: catch-up stopped after %d chunk(s); another sync holds the account lock. Not claiming completeness.',
                $accountId,
                $result['chunks'],
            ));

            return;
        }

        $this->warnIfTruncated($accountId);
        $this->warn(sprintf(
            'Account %d: catch-up still open after %d chunk(s) (chunk budget reached). Re-run to continue.',
            $accountId,
            $result['chunks'],
        ));
    }

    private function warnIfTruncated(int $accountId): void
    {
        $account = BankAccount::query()->find($accountId);

        if (! $account instanceof BankAccount) {
            return;
        }

        $run = BankSyncRun::query()
            ->where('accounting_bank_account_id', $accountId)
            ->latest('id')
            ->first();

        if ($run instanceof BankSyncRun && $run->requested_from_date !== null) {
            $this->warn(sprintf(
                'Account %d: only %s to %s synchronized; coverage gap remains from %s.',
                $accountId,
                $run->from_date?->toDateString() ?? 'unknown',
                $run->to_date?->toDateString() ?? 'unknown',
                $run->requested_from_date->toDateString(),
            ));

            if ($account->catch_up_from instanceof \DateTimeInterface) {
                $remainingDays = $account->catch_up_from->diffInDays(Carbon::today());
                $chunks = (int) ceil($remainingDays / (int) config('filament-accounting.banking.fints.sync.max_range_days', 90));
                $this->warn(sprintf(
                    '  Catch-up gap: %s → today (%d days). Approximately %d chunk(s) remaining; re-run (or omit --no-drain) to continue.',
                    $account->catch_up_from->toDateString(),
                    $remainingDays,
                    max(1, $chunks),
                ));
            }
        }
    }

    private function warnIfEvidenceGap(int $accountId): void
    {
        $run = BankSyncRun::query()
            ->where('accounting_bank_account_id', $accountId)
            ->latest('id')
            ->first();

        if (! $run instanceof BankSyncRun) {
            return;
        }

        $evidence = $run->reconciliation_evidence;
        if (! is_array($evidence) || ! isset($evidence['status'])) {
            $this->warn(sprintf(
                'Account %d: sync retained no statement/balance reconciliation evidence; do not treat success as completeness.',
                $accountId,
            ));

            return;
        }

        $status = (string) $evidence['status'];
        if ($status === 'matched') {
            return;
        }

        if ($status === 'mismatched') {
            $this->error(sprintf(
                'Account %d: statement/balance reconciliation mismatched (delta_minor=%s). Sync is not completeness proof.',
                $accountId,
                $evidence['delta_minor'] ?? 'n/a',
            ));

            return;
        }

        $this->warn(sprintf(
            'Account %d: statement/balance evidence status=%s; successful sync alone is not completeness proof.',
            $accountId,
            $status,
        ));
    }
}

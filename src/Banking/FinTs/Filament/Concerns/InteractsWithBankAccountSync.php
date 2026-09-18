<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Services\BalanceSyncService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Models\AccountingBankAccount;
use Livewire\Attributes\Locked;

trait InteractsWithBankAccountSync
{
    use InteractsWithScaChallenge;

    #[Locked]
    public ?int $combinedBankSyncAccountId = null;

    #[Locked]
    public ?string $combinedBankSyncStage = null;


    public function continueCatchUpDrain(AccountingBankAccount $account): void
    {
        $result = app(TransactionSyncService::class)->drainCatchUp(
            $account,
            app(AccountingActorResolver::class)->resolve(),
            request()->fullUrl(),
        );

        if (($result['stopped_for'] ?? null) === 'sca') {
            Notification::make()
                ->title(__('filament-accounting::banking/fints/notifications.sca_required'))
                ->warning()
                ->send();
            $this->refreshBankSyncUi();

            return;
        }

        if ($result['complete'] ?? false) {
            Notification::make()
                ->title(__('filament-accounting::banking/fints/notifications.catch_up_complete'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('filament-accounting::banking/fints/notifications.catch_up_still_open'))
                ->body(__('filament-accounting::banking/fints/notifications.catch_up_still_open_body', [
                    'chunks' => (int) ($result['chunks'] ?? 0),
                    'stopped' => (string) ($result['stopped_for'] ?? 'unknown'),
                ]))
                ->warning()
                ->send();
        }

        $this->refreshBankSyncUi();
    }

    public function syncBankAccountBalance(AccountingBankAccount $account): void
    {
        $outcome = $this->runBalanceSync($account);
        if ($this->openSca($outcome)) {
            return;
        }

        $this->notifySyncCompleted('balances_synced');
        $this->refreshBankSyncUi();
    }

    public function syncBankAccountTransactions(AccountingBankAccount $account): void
    {
        $outcome = $this->runTransactionSync($account);
        if ($this->openSca($outcome)) {
            return;
        }

        $this->notifySyncCompleted('transactions_synced');
        $this->refreshBankSyncUi();
    }

    public function syncBankAccountTransactionsAndBalance(AccountingBankAccount $account): void
    {
        $this->combinedBankSyncAccountId = (int) $account->getKey();
        $this->combinedBankSyncStage = 'balance';

        try {
            $outcome = $this->runBalanceSync($account);
            if ($this->openSca($outcome)) {
                return;
            }

            $this->continueCombinedBankSync();
        } catch (Halt $halt) {
            $this->clearCombinedBankSync();

            throw $halt;
        }
    }

    protected function afterScaCompleted(ScaOutcome $outcome): void
    {
        $operation = $outcome->session?->operation_type;

        if ($this->combinedBankSyncStage === 'balance' && $operation === ScaOperationType::SyncBalances) {
            $this->continueCombinedBankSync();

            return;
        }

        if ($this->combinedBankSyncStage === 'transactions' && $operation === ScaOperationType::SyncTransactions) {
            $this->finishCombinedBankSync();

            return;
        }

        $this->refreshBankSyncUi();
    }

    protected function shouldNotifyScaCompleted(ScaOperationType $operation): bool
    {
        unset($operation);

        return $this->combinedBankSyncStage === null;
    }

    private function continueCombinedBankSync(): void
    {
        $account = AccountingBankAccount::query()
            ->usable()
            ->findOrFail($this->combinedBankSyncAccountId);

        $this->combinedBankSyncStage = 'transactions';

        try {
            $outcome = $this->runTransactionSync($account);
            if ($this->openSca($outcome)) {
                return;
            }

            $this->finishCombinedBankSync();
        } catch (Halt $halt) {
            $this->clearCombinedBankSync();

            throw $halt;
        }
    }

    private function finishCombinedBankSync(): void
    {
        $this->clearCombinedBankSync();
        $this->notifySyncCompleted('transactions_and_balances_synced');
        $this->refreshBankSyncUi();
    }

    private function clearCombinedBankSync(): void
    {
        $this->combinedBankSyncAccountId = null;
        $this->combinedBankSyncStage = null;
    }

    private function runBalanceSync(AccountingBankAccount $account): ScaOutcome
    {
        return FintsUi::run(fn (): ScaOutcome => app(BalanceSyncService::class)->sync(
            $account,
            app(AccountingActorResolver::class)->resolve(),
            request()->fullUrl(),
        ));
    }

    private function runTransactionSync(AccountingBankAccount $account): ScaOutcome
    {
        return FintsUi::run(fn (): ScaOutcome => app(TransactionSyncService::class)->sync(
            $account,
            actor: app(AccountingActorResolver::class)->resolve(),
            returnUrl: request()->fullUrl(),
        ));
    }

    private function notifySyncCompleted(string $message): void
    {
        Notification::make()
            ->title(__("filament-accounting::banking/fints/notifications.{$message}"))
            ->success()
            ->send();
    }

    private function refreshBankSyncUi(): void
    {
        $this->resetTable();
    }
}

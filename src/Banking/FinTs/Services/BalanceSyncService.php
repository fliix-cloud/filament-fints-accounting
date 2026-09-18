<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\GetBalance;
use Fhp\Segment\SAL\HISAL;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Events\BankBalancesSynced;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Database\Eloquent\Model;

class BalanceSyncService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly StatementBalanceReconciler $balanceReconciler,
        private readonly AccountingAuthorizer $authorizer,
    ) {}

    public function sync(BankAccount $account, ?Model $actor = null, ?string $returnUrl = null): ScaOutcome
    {
        $this->authorizer->authorize('sync_bank', $account);
        $this->assertUsable($account);

        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
        $run = BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'type' => SyncType::Balances,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);

        $client = $this->factory->make($connection);
        $action = GetBalance::create($account->toSepaAccount());
        $outcome = $this->sca->execute($connection, $action, ScaOperationType::SyncBalances, $client, $run, $returnUrl, $actor);

        if (! $outcome->isDone()) {
            $run->status = SyncStatus::RequiresAttention;
            $run->save();

            return $outcome;
        }

        $this->applyResults($account, $action);

        $account->refresh();
        $evidence = $this->balanceReconciler->forBalanceSnapshot($account);
        $run->reconciliation_evidence = $evidence->toArray();
        $run->status = SyncStatus::Completed;
        $run->item_count = 1;
        $run->finished_at = now();
        if ($evidence->isUnavailable()) {
            $run->error_code = 'statement_balance_evidence_unavailable';
            $run->error_message = 'Balance sync finished without a retained booked-balance snapshot.';
        }
        $run->save();
        event(new BankBalancesSynced($connection->id, $account->id));

        return $outcome;
    }

    public function applyResults(BankAccount $account, GetBalance $action): void
    {
        $this->assertUsable($account);

        foreach ($action->getBalances() as $hisal) {
            $this->applyHisal($account, $hisal);
        }
    }

    private function applyHisal(BankAccount $account, HISAL $hisal): void
    {
        $booked = $hisal->getGebuchterSaldo();
        $account->booked_balance_minor = ExactMoney::ofString((string) $booked->getAmount(), $booked->getCurrency() ?: $account->currency)->minorAmount;
        $account->currency = $booked->getCurrency() ?: $account->currency;
        $pending = $hisal->getSaldoDerVorgemerktenUmsaetze();
        $account->pending_balance_minor = $pending
            ? ExactMoney::ofString((string) $pending->getAmount(), $pending->getCurrency() ?: $account->currency)->minorAmount
            : null;
        $credit = $hisal->getKreditlinie();
        $account->credit_line_minor = $credit
            ? ExactMoney::ofString((string) $credit->wert, $credit->waehrung ?: $account->currency)->minorAmount
            : null;
        $available = $hisal->getVerfuegbarerBetrag();
        $account->available_amount_minor = $available
            ? ExactMoney::ofString((string) $available->wert, $available->waehrung ?: $account->currency)->minorAmount
            : null;
        $previousProductName = $account->product_name;
        $productName = $hisal->getKontoproduktbezeichnung() ?: $previousProductName;
        if (filled($productName) && $account->hasAutomaticDisplayName($previousProductName)) {
            $account->display_name = $productName;
        }
        $account->product_name = $productName;
        $account->balance_at = now();
        $account->last_balance_sync_at = now();
        $account->save();
    }

    private function assertUsable(BankAccount $account): void
    {
        if (! $account->isUsable()) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
    }
}

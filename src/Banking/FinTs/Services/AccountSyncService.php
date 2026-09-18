<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\GetSEPAAccounts;
use Fhp\Model\SEPAAccount;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Support\AccountFingerprint;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Model;

class AccountSyncService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly AccountingAuthorizer $authorizer,
    ) {}

    public function sync(BankConnection $connection, ?Model $actor = null, ?string $returnUrl = null): ScaOutcome
    {
        $this->authorizer->authorize('sync_bank', $connection);
        $run = BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'type' => SyncType::Accounts,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $client = $this->factory->make($connection);
            $action = GetSEPAAccounts::create();
            $outcome = $this->sca->execute($connection, $action, ScaOperationType::SyncAccounts, $client, $run, $returnUrl, $actor);

            if (! $outcome->isDone()) {
                $run->refresh();
                if ($run->status === SyncStatus::Running) {
                    $run->status = SyncStatus::RequiresAttention;
                    $run->save();
                }

                return $outcome;
            }

            return $outcome;
        } catch (\Throwable $e) {
            $run->status = SyncStatus::Failed;
            $run->error_message = ErrorMapper::map($e)->userMessage();
            $run->finished_at = now();
            $run->save();

            throw $e;
        }
    }

    /**
     * @param  array<int, SEPAAccount>  $accounts
     */
    public function persistAccounts(BankConnection $connection, array $accounts): void
    {
        $seen = [];

        foreach ($accounts as $sepa) {
            $fingerprint = AccountFingerprint::for($sepa);
            $seen[] = $fingerprint;

            $account = BankAccount::query()->firstOrNew([
                'bank_connection_id' => $connection->id,
                'fingerprint' => $fingerprint,
            ]);
            $isNew = ! $account->exists;
            $account->fill([
                'legal_entity_id' => $connection->legal_entity_id,
                'source' => 'fints',
                'external_account_id' => $fingerprint,
                'iban' => $sepa->getIban(),
                'bic' => $sepa->getBic(),
                'account_number' => $sepa->getAccountNumber(),
                'sub_account' => $sepa->getSubAccount(),
                'bank_code' => $sepa->getBlz(),
                'currency' => $account->currency ?: 'EUR',
                'is_available' => true,
            ]);
            if ($isNew) {
                $account->display_name = $sepa->getIban() ?: $sepa->getAccountNumber() ?: $fingerprint;
                $account->is_enabled = true;
            }
            $account->save();
        }

        $missing = BankAccount::query()
            ->where('bank_connection_id', $connection->id)
            ->whereNotIn('fingerprint', $seen)
            ->get();

        foreach ($missing as $account) {
            if ($account->is_available) {
                $account->is_available = false;
                $account->save();
            }
        }
    }
}

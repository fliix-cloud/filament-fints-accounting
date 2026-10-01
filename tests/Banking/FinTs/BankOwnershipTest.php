<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BalanceSyncService;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Exceptions\EntityIsolationException;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClient;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClientFactory;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Livewire actions receive bank models by client-supplied id. The services
 * behind them must refuse records of another company before any bank contact.
 */
class BankOwnershipTest extends TestCase
{
    #[Test]
    public function sync_services_refuse_another_companys_bank_records(): void
    {
        $this->actingAs($this->makeUser());
        $foreign = $this->makeEntity(['legal_name' => 'Fremd GmbH']);
        $foreignConnection = $this->connection($foreign);
        $foreignAccount = $this->account($foreignConnection);
        $foreignRun = BankSyncRun::query()->create([
            'bank_connection_id' => $foreignConnection->id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Failed,
            'started_at' => now(),
        ]);
        // The current company is the one created last.
        $this->makeEntity(['legal_name' => 'Eigene GmbH']);

        $client = new FakeFintsClient;
        $this->app->instance(FintsClientFactory::class, new FakeFintsClientFactory($client));

        foreach ([
            'accounts' => fn () => app(AccountSyncService::class)->sync($foreignConnection),
            'balances' => fn () => app(BalanceSyncService::class)->sync($foreignAccount),
            'transactions' => fn () => app(TransactionSyncService::class)->sync($foreignAccount),
            'catch-up' => fn () => app(TransactionSyncService::class)->drainCatchUp($foreignAccount),
            'acknowledge' => fn () => app(BankingBacklogService::class)->acknowledgeSyncRun($foreignRun),
        ] as $operation => $call) {
            try {
                $call();
                $this->fail("The {$operation} operation must refuse another company's records.");
            } catch (EntityIsolationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $client->loginCalls);
        $this->assertSame(1, BankSyncRun::query()->count());
    }

    private function connection(LegalEntity $entity): BankConnection
    {
        return BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Testbank',
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
        ]);
    }

    private function account(BankConnection $connection): AccountingBankAccount
    {
        return AccountingBankAccount::query()->create([
            'legal_entity_id' => $connection->legal_entity_id,
            'bank_connection_id' => $connection->getKey(),
            'display_name' => 'Geschäftskonto',
            'external_account_id' => 'account-'.$connection->getKey(),
            'fingerprint' => 'fingerprint-'.$connection->getKey(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'currency' => 'EUR',
            'account_holder_name' => 'Demo GmbH',
            'is_available' => true,
            'is_enabled' => true,
        ]);
    }
}

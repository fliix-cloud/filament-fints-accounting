<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Exceptions\ConcurrentBankSyncException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class ConcurrentCatchUpSyncTest extends TestCase
{
    #[Test]
    public function claim_fails_closed_when_another_transaction_sync_is_running(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);

        BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Running,
            'from_date' => Carbon::today()->subDays(10),
            'to_date' => Carbon::today()->subDays(1),
            'started_at' => now(),
        ]);

        $this->expectException(ConcurrentBankSyncException::class);
        $this->invokeClaim($account, $connection);
    }

    #[Test]
    public function claim_fails_closed_when_another_sync_awaits_sca(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);

        BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::RequiresAttention,
            'from_date' => Carbon::today()->subDays(10),
            'to_date' => Carbon::today()->subDays(1),
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $this->expectException(ConcurrentBankSyncException::class);
        $this->invokeClaim($account, $connection);
    }

    #[Test]
    public function claim_allows_new_sync_when_prior_requires_attention_is_finished(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);

        BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::RequiresAttention,
            'from_date' => Carbon::today()->subDays(10),
            'to_date' => Carbon::today()->subDays(1),
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
            'error_code' => 'statement_balance_mismatch',
        ]);

        $run = $this->invokeClaimReturning($account, $connection);
        $this->assertSame(SyncStatus::Running, $run->status);
        $this->assertTrue($run->exists);
    }

    #[Test]
    public function drain_catch_up_stops_fail_closed_on_concurrent_claim_without_claiming_completeness(): void
    {
        $account = Mockery::mock(AccountingBankAccount::class)->makePartial();
        $account->catch_up_from = Carbon::today()->subDays(120);
        $account->shouldReceive('refresh')->andReturnSelf();

        $svc = Mockery::mock(TransactionSyncService::class)->makePartial();
        $svc->shouldReceive('sync')
            ->once()
            ->andThrow(new ConcurrentBankSyncException('blocked'));

        $result = $svc->drainCatchUp($account);

        $this->assertFalse($result['complete']);
        $this->assertSame('concurrent', $result['stopped_for']);
        $this->assertSame(0, $result['chunks']);
    }

    private function invokeClaim(AccountingBankAccount $account, BankConnection $connection): void
    {
        $this->invokeClaimReturning($account, $connection);
    }

    private function invokeClaimReturning(AccountingBankAccount $account, BankConnection $connection): BankSyncRun
    {
        $svc = app(TransactionSyncService::class);
        $method = new ReflectionMethod($svc, 'claimExclusiveTransactionSync');
        $method->setAccessible(true);

        return $method->invoke(
            $svc,
            $account,
            $connection,
            Carbon::today()->subDays(5),
            Carbon::today(),
            null,
        );
    }

    private function makeBankConnection($entity): BankConnection
    {
        $connection = new BankConnection([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Test Connection',
            'bank_code' => 'TESTBANK00',
            'endpoint_url' => 'https://example.com/fints',
            'username' => 'testuser',
            'pin' => 'testpin',
            'status' => BankConnectionStatus::Active,
        ]);
        $connection->save();

        return $connection;
    }

    private function makeBankAccountWithConnection($entity, BankConnection $connection): AccountingBankAccount
    {
        $account = $this->makeBankAccount($entity);
        $account->bank_connection_id = $connection->id;
        $account->source = 'fints';
        $account->external_account_id = 'acc-'.$account->id;
        $account->save();

        return $account;
    }
}

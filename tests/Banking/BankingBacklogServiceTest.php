<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class BankingBacklogServiceTest extends TestCase
{
    #[Test]
    public function summarize_reports_catch_up_failed_runs_intakes_and_review_lines(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(40);
        $account->save();

        $failed = $this->makeRun($connection, $account, SyncStatus::Failed);
        $failed->error_message = 'bank error';
        $failed->save();

        PurchaseInvoiceIntake::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'identity' => 'intake-1',
            'disk' => 'local',
            'files' => ['primary' => ['filename' => 'a.xml', 'path' => 'a.xml', 'sha256' => 'abc', 'size' => 3]],
            'preserved_files' => [],
            'status' => 'pending',
        ]);

        BankStatementLine::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'bank_account_id' => $account->getKey(),
            'source' => 'fints',
            'external_id' => 'line-1',
            'amount_minor' => 100,
            'currency' => 'EUR',
            'booking_date' => Carbon::today(),
            'source_status' => StatementLineStatus::Booked,
            'needs_review' => true,
            'review_reason' => ['reason' => 'ambiguous match'],
            'first_imported_at' => now(),
            'last_imported_at' => now(),
        ]);

        $summary = app(BankingBacklogService::class)->summarize((int) $entity->getKey());

        $this->assertTrue($summary['has_open_backlog']);
        $this->assertSame(1, $summary['catch_up_accounts']);
        $this->assertSame(1, $summary['open_sync_runs']);
        $this->assertSame(1, $summary['pending_intakes']);
        $this->assertSame(1, $summary['needs_review_lines']);
        $kinds = array_column($summary['items'], 'kind');
        $this->assertContains('catch_up', $kinds);
        $this->assertContains('sync_run', $kinds);
        $this->assertContains('intake', $kinds);
        $this->assertContains('statement_review', $kinds);
    }

    #[Test]
    public function acknowledge_removes_a_failed_run_from_open_backlog_without_clearing_catch_up(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(10);
        $account->save();

        $failed = $this->makeRun($connection, $account, SyncStatus::Failed);
        $failed->save();

        $service = app(BankingBacklogService::class);
        $service->acknowledgeSyncRun($failed, 'seen by operator');

        $summary = $service->summarize((int) $entity->getKey());
        $this->assertSame(0, $summary['open_sync_runs']);
        $this->assertSame(1, $summary['catch_up_accounts']);
        $this->assertTrue($summary['has_open_backlog']);
        $account->refresh();
        $this->assertNotNull($account->catch_up_from);
        $this->assertTrue($service->isAcknowledged($failed->refresh()));
    }

    #[Test]
    public function completed_runs_with_mismatched_evidence_remain_in_backlog_until_acked(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);

        $run = $this->makeRun($connection, $account, SyncStatus::Completed);
        $run->reconciliation_evidence = ['status' => 'mismatched', 'delta_minor' => 5];
        $run->save();

        $service = app(BankingBacklogService::class);
        $summary = $service->summarize((int) $entity->getKey());
        $this->assertSame(1, $summary['open_sync_runs']);

        $service->acknowledgeSyncRun($run);
        $summary = $service->summarize((int) $entity->getKey());
        $this->assertSame(0, $summary['open_sync_runs']);
        $this->assertFalse($summary['has_open_backlog']);
    }

    #[Test]
    public function backlog_command_fails_closed_when_backlog_is_open(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(5);
        $account->save();

        $this->artisan('filament-accounting:banking-backlog', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ])->assertFailed();
    }

    #[Test]
    public function backlog_command_succeeds_when_empty(): void
    {
        $entity = $this->makeEntity();

        $this->artisan('filament-accounting:banking-backlog', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ])->assertSuccessful();
    }

    private function makeRun(BankConnection $connection, AccountingBankAccount $account, SyncStatus $status): BankSyncRun
    {
        return BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => $status,
            'from_date' => Carbon::today()->subDays(5),
            'to_date' => Carbon::today(),
            'item_count' => 0,
            'started_at' => now()->subHour(),
            'finished_at' => $status === SyncStatus::Running ? null : now(),
        ]);
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
        $account->is_available = true;
        $account->is_enabled = true;
        $account->save();

        return $account;
    }
}

<?php

namespace FilamentAccounting\Tests\Banking;

use Fhp\Model\StatementOfAccount\Statement;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction;
use FilamentAccounting\Banking\FinTs\Data\StatementBalanceEvidence;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\StatementBalanceReconciler;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class StatementBalanceReconcilerTest extends TestCase
{
    #[Test]
    public function statement_opening_closing_and_imported_booked_net_match(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->forceFill([
            'currency' => 'EUR',
            'booked_balance_minor' => 15_000,
            'balance_at' => Carbon::parse('2026-09-18 12:00:00'),
            'last_balance_sync_at' => Carbon::parse('2026-09-18 12:00:00'),
        ])->save();

        BankStatementLine::query()->create([
            'legal_entity_id' => $entity->id,
            'bank_account_id' => $account->id,
            'source' => 'fints',
            'external_id' => 'tx-1',
            'amount_minor' => 5_000,
            'currency' => 'EUR',
            'booking_date' => '2026-09-18',
            'value_date' => '2026-09-18',
            'source_status' => StatementLineStatus::Booked,
            'first_imported_at' => now(),
            'last_imported_at' => now(),
        ]);

        $statement = $this->makeStatement(
            date: '2026-09-18',
            startBalance: 100.00,
            creditDebit: Statement::CD_CREDIT,
            endBalance: 150.00,
            transactions: [
                ['amount' => 50.00, 'credit' => true],
            ],
        );

        $evidence = app(StatementBalanceReconciler::class)->forStatement(
            $account,
            $statement,
            Carbon::parse('2026-09-18'),
            Carbon::parse('2026-09-18'),
        );

        $this->assertTrue($evidence->isMatched());
        $this->assertSame(10_000, $evidence->statementOpeningMinor);
        $this->assertSame(15_000, $evidence->statementClosingMinor);
        $this->assertSame(5_000, $evidence->statementTxNetMinor);
        $this->assertSame(5_000, $evidence->importedBookedNetMinor);
        $this->assertSame(0, $evidence->deltaMinor);
    }

    #[Test]
    public function imported_net_mismatch_fails_closed_as_mismatched(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->forceFill(['currency' => 'EUR'])->save();

        // Imported booked net is empty while the statement carries +50.00.
        $statement = $this->makeStatement(
            date: '2026-09-18',
            startBalance: 100.00,
            creditDebit: Statement::CD_CREDIT,
            endBalance: 150.00,
            transactions: [
                ['amount' => 50.00, 'credit' => true],
            ],
        );

        $evidence = app(StatementBalanceReconciler::class)->forStatement(
            $account,
            $statement,
            Carbon::parse('2026-09-18'),
            Carbon::parse('2026-09-18'),
        );

        $this->assertTrue($evidence->isMismatched());
        $this->assertContains('imported_booked_net_differs_from_statement_tx_net', $evidence->notes);
    }

    #[Test]
    public function empty_statement_without_balances_is_unavailable_not_a_match(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);

        $evidence = app(StatementBalanceReconciler::class)->forStatement(
            $account,
            new StatementOfAccount,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-18'),
        );

        $this->assertTrue($evidence->isUnavailable());
        $this->assertFalse($evidence->isMatched());
    }

    #[Test]
    public function mark_sync_completed_persists_evidence_and_fails_closed_on_mismatch(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->forceFill(['currency' => 'EUR'])->save();

        $run = BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Running,
            'from_date' => '2026-09-18',
            'to_date' => '2026-09-18',
            'item_count' => 0,
            'started_at' => now(),
        ]);

        $evidence = new StatementBalanceEvidence(
            status: StatementBalanceEvidence::STATUS_MISMATCHED,
            kind: 'statement_balance',
            currency: 'EUR',
            windowFrom: '2026-09-18',
            windowTo: '2026-09-18',
            statementOpeningMinor: 10_000,
            statementClosingMinor: 15_000,
            statementTxNetMinor: 5_000,
            importedBookedNetMinor: 0,
            deltaMinor: 5_000,
            notes: ['imported_booked_net_differs_from_statement_tx_net'],
        );

        app(TransactionSyncService::class)->markSyncCompleted(
            $account,
            $run,
            ['imported' => 0, 'updated' => 0],
            $evidence,
        );

        $run->refresh();
        $this->assertSame(SyncStatus::RequiresAttention, $run->status);
        $this->assertSame('statement_balance_mismatch', $run->error_code);
        $this->assertIsArray($run->reconciliation_evidence);
        $this->assertSame('mismatched', $run->reconciliation_evidence['status']);
    }

    #[Test]
    public function balance_snapshot_is_captured_not_treated_as_window_match(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->forceFill([
            'currency' => 'EUR',
            'booked_balance_minor' => 42_00,
            'balance_at' => now(),
            'last_balance_sync_at' => now(),
        ])->save();

        $evidence = app(StatementBalanceReconciler::class)->forBalanceSnapshot($account);

        $this->assertSame(StatementBalanceEvidence::STATUS_CAPTURED, $evidence->status);
        $this->assertFalse($evidence->isMatched());
        $this->assertSame(42_00, $evidence->accountBookedBalanceMinor);
    }

    /**
     * @param  list<array{amount: float, credit: bool}>  $transactions
     */
    private function makeStatement(
        string $date,
        float $startBalance,
        string $creditDebit,
        ?float $endBalance,
        array $transactions,
    ): StatementOfAccount {
        $day = new Statement;
        $day->setDate(new \DateTime($date));
        $day->setStartBalance($startBalance);
        $day->setCreditDebit($creditDebit);
        if ($endBalance !== null) {
            $day->setEndBalance($endBalance);
        }

        foreach ($transactions as $row) {
            $tx = new Transaction;
            $tx->setAmount($row['amount']);
            $tx->setCreditDebit($row['credit'] ? Transaction::CD_CREDIT : Transaction::CD_DEBIT);
            $tx->setBooked(true);
            $tx->setBookingDate(new \DateTime($date));
            $tx->setValutaDate(new \DateTime($date));
            $tx->setIsStorno(false);
            $day->addTransaction($tx);
        }

        $statement = new StatementOfAccount;
        // StatementOfAccount has no public adder on all versions; use reflection.
        $ref = new \ReflectionProperty($statement, 'statements');
        $ref->setAccessible(true);
        $ref->setValue($statement, [$day]);

        return $statement;
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

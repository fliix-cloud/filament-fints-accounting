<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\BankTransactionSourceVersion;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PendingToBookedPromotionTest extends TestCase
{
    #[Test]
    public function pending_to_booked_promotion_updates_in_place_and_resync_does_not_duplicate(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $importer = app(UnifiedBankTransactionImporter::class);
        $base = [
            'amountMinor' => 9900,
            'currency' => 'EUR',
            'driverKey' => 'fints',
            'sourceAccountExternalId' => $account->external_account_id,
            'counterpartyName' => 'Supplier AG',
            'counterpartyIban' => 'DE89370400440532013000',
            'purpose' => 'Order 99',
            'endToEndId' => 'E2E-99',
        ];

        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'pending-99',
            'sourceStatus' => 'pending',
            'sourcePayload' => ['fingerprint' => 'pending-99', 'status' => 'pending'],
            'sourceHash' => hash('sha256', 'pending-99'),
        ]))]);
        $transactionId = BankStatementLine::query()->sole()->getKey();

        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'booked-99',
            'bookingDate' => '2026-09-18',
            'sourceStatus' => 'booked',
            'sourcePayload' => ['fingerprint' => 'booked-99', 'status' => 'booked'],
            'sourceHash' => hash('sha256', 'booked-99'),
        ]))]);

        $transaction = BankStatementLine::query()->sole();
        $this->assertSame($transactionId, $transaction->getKey());
        $this->assertSame('booked-99', $transaction->external_id);
        $this->assertSame(StatementLineStatus::Booked, $transaction->source_status);

        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'booked-99',
            'bookingDate' => '2026-09-18',
            'sourceStatus' => 'booked',
            'sourcePayload' => ['fingerprint' => 'booked-99', 'status' => 'booked'],
            'sourceHash' => hash('sha256', 'booked-99'),
        ]))]);

        $this->assertSame(1, BankStatementLine::query()->count());
        $this->assertSame(2, BankTransactionSourceVersion::query()
            ->where('bank_transaction_id', $transactionId)
            ->count());
    }

    #[Test]
    public function ambiguous_pending_matches_fail_closed_without_merging(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $importer = app(UnifiedBankTransactionImporter::class);
        $shared = [
            'amountMinor' => 5000,
            'currency' => 'EUR',
            'driverKey' => 'fints',
            'sourceAccountExternalId' => $account->external_account_id,
            'counterpartyName' => 'Twin Payee',
            'purpose' => 'Same purpose',
            'endToEndId' => 'E2E-TWIN',
        ];

        $importer->import($account, [new BankStatementLineData(...array_merge($shared, [
            'externalId' => 'pending-a',
            'sourceStatus' => 'pending',
            'sourcePayload' => ['fingerprint' => 'pending-a'],
            'sourceHash' => hash('sha256', 'pending-a'),
        ]))]);
        $importer->import($account, [new BankStatementLineData(...array_merge($shared, [
            'externalId' => 'pending-b',
            'sourceStatus' => 'pending',
            'sourcePayload' => ['fingerprint' => 'pending-b'],
            'sourceHash' => hash('sha256', 'pending-b'),
        ]))]);
        $this->assertSame(2, BankStatementLine::query()->count());

        $importer->import($account, [new BankStatementLineData(...array_merge($shared, [
            'externalId' => 'booked-twin',
            'bookingDate' => '2026-09-18',
            'sourceStatus' => 'booked',
            'sourcePayload' => ['fingerprint' => 'booked-twin'],
            'sourceHash' => hash('sha256', 'booked-twin'),
        ]))]);

        $this->assertSame(3, BankStatementLine::query()->count());
        $this->assertSame(2, BankStatementLine::query()->where('source_status', StatementLineStatus::Pending)->count());
        $this->assertSame(1, BankStatementLine::query()->where('source_status', StatementLineStatus::Booked)->count());
    }

    #[Test]
    public function weak_identity_without_reference_or_counterparty_fails_closed(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $importer = app(UnifiedBankTransactionImporter::class);

        $importer->import($account, [new BankStatementLineData(
            externalId: 'pending-weak',
            amountMinor: 1200,
            currency: 'EUR',
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            sourceStatus: 'pending',
            sourcePayload: ['fingerprint' => 'pending-weak'],
            sourceHash: hash('sha256', 'pending-weak'),
        )]);

        $importer->import($account, [new BankStatementLineData(
            externalId: 'booked-weak',
            amountMinor: 1200,
            currency: 'EUR',
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: '2026-09-18',
            sourceStatus: 'booked',
            sourcePayload: ['fingerprint' => 'booked-weak'],
            sourceHash: hash('sha256', 'booked-weak'),
        )]);

        $this->assertSame(2, BankStatementLine::query()->count());
        $this->assertSame(1, BankStatementLine::query()->where('source_status', StatementLineStatus::Pending)->count());
        $this->assertSame(1, BankStatementLine::query()->where('source_status', StatementLineStatus::Booked)->count());
    }
}

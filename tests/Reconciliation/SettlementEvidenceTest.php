<?php

namespace FilamentAccounting\Tests\Reconciliation;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReverseReconciliation;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SettlementEvidenceTest extends TestCase
{
    #[Test]
    public function finalized_settlement_stores_frozen_open_item_and_document_evidence(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Evidence', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('evidence-pay', 11900, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme GmbH', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'evidence-pay')->firstOrFail();

        app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ]);

        $settlement = Settlement::query()->where('open_item_id', $invoice->openItem->getKey())->sole();
        $this->assertIsArray($settlement->evidence);
        $this->assertSame(1, $settlement->evidence['schema_version']);
        $this->assertSame((int) $invoice->openItem->getKey(), $settlement->evidence['open_item']['id']);
        $this->assertSame($invoice->uuid, $settlement->evidence['document']['uuid']);
        $this->assertSame($invoice->number, $settlement->evidence['document']['number']);
        $this->assertSame(11900, $settlement->evidence['settlement']['amount_minor']);
        $this->assertSame(0, $settlement->evidence['open_item']['remaining_after_minor']);
        $this->assertSame($line->uuid, $settlement->evidence['statement_line']['uuid']);
        $this->assertSame($customer->uuid, $settlement->evidence['party']['uuid']);
    }

    #[Test]
    public function reversing_a_settlement_binds_reversal_evidence_to_the_original(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Reverse evidence', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('evidence-rev', 11900, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'evidence-rev')->firstOrFail();
        $reconciliation = app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ]);

        $original = Settlement::query()->where('is_reversed', false)->whereNull('reverses_id')->sole();
        app(ReverseReconciliation::class)->handle($reconciliation, '2026-03-11', 'correction');

        $reversal = Settlement::query()->where('reverses_id', $original->getKey())->sole();
        $this->assertTrue($reversal->is_reversed);
        $this->assertSame((int) $original->getKey(), $reversal->evidence['reverses_settlement_id']);
        $this->assertSame($original->uuid, $reversal->evidence['reverses_settlement_uuid']);
        $this->assertSame($original->evidence['document']['uuid'], $reversal->evidence['original_evidence']['document']['uuid']);
    }
}

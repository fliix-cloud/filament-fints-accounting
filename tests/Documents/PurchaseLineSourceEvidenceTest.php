<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PurchaseLineSourceEvidenceTest extends TestCase
{
    #[Test]
    public function converted_purchase_lines_without_source_hash_fail_closed(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Supplier GmbH']);

        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.purchase_line_source_evidence_required'));

        app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $supplier->getKey(),
            'issue_date' => '2026-03-01',
            'receipt_date' => '2026-03-02',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Imported without hash',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
                'imported_tax_code' => 'S',
                'source_line_index' => 0,
            ]],
        ]);
    }
}

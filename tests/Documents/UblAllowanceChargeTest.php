<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: AllowanceCharge parsing — supported subset imports with metadata;
 * unknown or non-reconciling cases fail closed with a clear DocumentException.
 */
class UblAllowanceChargeTest extends TestCase
{
    #[Test]
    public function line_allowance_baked_into_line_extension_amount_succeeds_with_metadata(): void
    {
        $xml = $this->fixture('line-allowance-baked.xml');
        $result = app(UblEInvoiceParser::class)->parse($xml, 'line-allowance-baked.xml');

        $this->assertTrue($result->valid, implode('; ', $result->errors));
        $this->assertSame(15000, $result->netMinor);
        $this->assertSame(2850, $result->taxMinor);
        $this->assertSame(17850, $result->grossMinor);
        $this->assertSame(15000, $result->lines[0]['line_net_minor']);
        $this->assertArrayHasKey('allowance_charges', $result->lines[0]);
        $ac = $result->lines[0]['allowance_charges'][0];
        $this->assertFalse($ac['charge_indicator']);
        $this->assertSame(5000, $ac['amount_minor']);
        $this->assertSame('Discount', $ac['reason']);
        $this->assertSame('95', $ac['reason_code']);
        $this->assertSame('25.00', $ac['percent']);
    }

    #[Test]
    public function non_reconciling_line_allowance_charge_fails_closed(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
            'detail' => 'line expected 7500, got 10000',
        ]));

        app(UblEInvoiceParser::class)->parse(
            $this->fixture('line-allowance-mismatch.xml'),
            'line-allowance-mismatch.xml',
        );
    }

    #[Test]
    public function unsupported_base_amount_allowance_charge_fails_closed(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.unsupported_allowance_charge', [
            'detail' => 'BaseAmount is not supported (line)',
        ]));

        app(UblEInvoiceParser::class)->parse(
            $this->fixture('line-allowance-baseamount.xml'),
            'line-allowance-baseamount.xml',
        );
    }

    #[Test]
    public function document_level_allowance_that_changes_net_fails_closed(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.unsupported_allowance_charge', [
            'detail' => 'document-level AllowanceCharge changes net below sum of line nets and cannot be posted',
        ]));

        app(UblEInvoiceParser::class)->parse(
            $this->fixture('document-allowance-unsupported.xml'),
            'document-allowance-unsupported.xml',
        );
    }

    #[Test]
    public function document_level_zero_net_effect_allowance_charge_succeeds_with_metadata(): void
    {
        $result = app(UblEInvoiceParser::class)->parse(
            $this->fixture('document-allowance-zero-net.xml'),
            'document-allowance-zero-net.xml',
        );

        $this->assertTrue($result->valid, implode('; ', $result->errors));
        $this->assertSame(10000, $result->netMinor);
        $this->assertSame(1900, $result->taxMinor);
        $this->assertSame(11900, $result->grossMinor);
        $this->assertArrayHasKey('document_allowance_charges', $result->meta);
        $this->assertCount(2, $result->meta['document_allowance_charges']);
        $this->assertFalse($result->meta['document_allowance_charges'][0]['charge_indicator']);
        $this->assertSame(500, $result->meta['document_allowance_charges'][0]['amount_minor']);
        $this->assertTrue($result->meta['document_allowance_charges'][1]['charge_indicator']);
        $this->assertSame(500, $result->meta['document_allowance_charges'][1]['amount_minor']);
    }

    #[Test]
    public function document_allowance_totals_mismatch_is_blocked(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
            'detail' => 'document AllowanceTotalAmount does not match AllowanceCharge sums',
        ]));

        app(UblEInvoiceParser::class)->parse(
            $this->fixture('document-allowance-lmt-mismatch.xml'),
            'document-allowance-lmt-mismatch.xml',
        );
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__).'/Fixtures/ubl/'.$name;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}

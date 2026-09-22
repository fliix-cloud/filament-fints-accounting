<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: the ZUGFeRD (CII) parse path had no direct unit coverage. These tests use
 * the adapter's own generator to produce a valid EN16931 invoice, then parse it
 * back, proving the round trip and the exact tax-rate to basis-point conversion.
 */
class ZugferdEInvoiceAdapterTest extends TestCase
{
    #[Test]
    public function a_generated_invoice_round_trips_with_exact_tax_basis_points(): void
    {
        $adapter = new ZugferdEInvoiceAdapter;
        $snapshot = [
            'number' => 'RE-100',
            'issue_date' => '2026-09-11',
            'currency' => 'EUR',
            'seller' => [
                'legal_name' => 'Seller GmbH',
                'vat_id' => 'DE123456789',
                'address_line1' => 'Musterweg 1',
                'postal_code' => '10115',
                'city' => 'Berlin',
                'country_code' => 'DE',
            ],
            'buyer' => [
                'legal_name' => 'Buyer GmbH',
                'addresses' => [[
                    'line1' => 'Kundenstr. 2',
                    'postal_code' => '80331',
                    'city' => 'München',
                    'country_code' => 'DE',
                ]],
            ],
            'gross_minor' => 17250,
            'net_minor' => 15000,
            'tax_minor' => 2250,
            'lines' => [
                [
                    'description' => 'Standard 19%',
                    'quantity' => '1',
                    'unit' => 'C62',
                    'unit_price_minor' => 10000,
                    'tax_rate_bp' => 1900,
                    'tax_category' => 'standard',
                    'net_minor' => 10000,
                    'tax_minor' => 1900,
                ],
                [
                    'description' => 'Reduced 7%',
                    'quantity' => '1',
                    'unit' => 'C62',
                    'unit_price_minor' => 5000,
                    'tax_rate_bp' => 700,
                    'tax_category' => 'standard',
                    'net_minor' => 5000,
                    'tax_minor' => 350,
                ],
            ],
            'payment' => [],
        ];

        $xml = $adapter->generate($snapshot);
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);

        $parsed = $adapter->parse($xml, 'invoice.xml');

        $this->assertTrue($parsed->valid, implode('; ', $parsed->errors));
        $this->assertSame('zugferd', $parsed->formatKey);
        $this->assertSame('RE-100', $parsed->documentNumber);
        $this->assertSame('2026-09-11', $parsed->issueDate);
        $this->assertSame('EUR', $parsed->currency);
        $this->assertSame(15000, $parsed->netMinor);
        $this->assertSame(2250, $parsed->taxMinor);
        $this->assertSame(17250, $parsed->grossMinor);
        $this->assertSame('DE123456789', $parsed->sellerVatId);
        $this->assertSame('Berlin', $parsed->sellerCity);
        $this->assertSame('10115', $parsed->sellerPostalCode);
        $this->assertSame('Seller GmbH', $parsed->sellerName);
        $this->assertSame('Buyer GmbH', $parsed->buyerName);
        $this->assertSame('DE', $parsed->buyerCountryCode);
        $this->assertSame('380', $parsed->invoiceTypeCode);
        $this->assertSame('urn:cen.eu:en16931:2017', $parsed->customizationId);
        $this->assertCount(2, $parsed->vatBreakdown);

        $this->assertCount(2, $parsed->lines);
        $byRate = array_combine(
            array_map(fn (array $line): int => (int) $line['tax_rate_bp'], $parsed->lines),
            $parsed->lines,
        );
        $this->assertSame(1900, (int) $byRate[1900]['tax_rate_bp']);
        $this->assertSame('Standard 19%', $byRate[1900]['description']);
        $this->assertSame(700, (int) $byRate[700]['tax_rate_bp']);
        $this->assertSame('Reduced 7%', $byRate[700]['description']);
    }
}

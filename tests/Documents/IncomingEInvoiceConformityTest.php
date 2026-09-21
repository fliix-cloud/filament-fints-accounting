<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Documents\ValidateIncomingEInvoice;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: incoming e-invoices are schema-checked and run through the DE-EUR EN 16931
 * business-rule subset before a purchase document is created. Failures stay
 * preserved on intake and are not booked.
 */
class IncomingEInvoiceConformityTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
    }

    #[Test]
    public function a_valid_ubl_subset_invoice_imports_and_records_subset_validation(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('tax-s-19.xml');

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'tax-s-19.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertTrue($result->document->e_invoice_meta['validated']);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame(
            'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
            $result->document->e_invoice_meta['specification_identifier'],
        );
        $this->assertSame('DE-19', $result->document->lines->sole()->tax_code);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    public function a_valid_xrechnung_ubl_invoice_imports_through_the_reception_subset(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('xrechnung-3-s19.xml');

        $parsed = app(UblEInvoiceParser::class)->parse($xml, 'xrechnung-3-s19.xml');
        $this->assertSame('Buyer GmbH', $parsed->buyerName);
        $this->assertSame('DE', $parsed->buyerCountryCode);
        $this->assertSame('München', $parsed->buyerCity);
        $this->assertSame('80331', $parsed->buyerPostalCode);
        $this->assertSame('380', $parsed->invoiceTypeCode);
        $this->assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $parsed->customizationId);
        $this->assertSame('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', $parsed->profileId);
        $this->assertCount(1, $parsed->vatBreakdown);
        $this->assertSame('S', $parsed->vatBreakdown[0]['category']);
        $this->assertSame(1900, $parsed->vatBreakdown[0]['rate_bp']);
        $this->assertSame(10000, $parsed->vatBreakdown[0]['taxable_minor']);
        $this->assertSame(1900, $parsed->vatBreakdown[0]['tax_minor']);

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'xrechnung-3-s19.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    public function a_valid_en16931_core_ubl_invoice_imports_without_a_profile_id(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('en16931-core-s19.xml');

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'en16931-core-s19.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame('urn:cen.eu:en16931:2017', $result->document->e_invoice_meta['specification_identifier']);
        $this->assertNull($result->document->e_invoice_meta['business_process_id']);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    public function a_generated_cii_en16931_invoice_imports_after_schema_and_business_rules(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = app(ZugferdEInvoiceAdapter::class)->generate($this->ciiSnapshot());

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'cii-en16931.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('zugferd', $result->document->e_invoice_meta['format']);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame(17250, (int) $result->document->gross_minor);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    #[DataProvider('storedCiiReceptionFixtures')]
    public function stored_cii_reception_fixtures_import(string $fixture, string $specification, ?string $processId): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->fixture('cii', $fixture);

        $result = app(ImportPurchaseInvoice::class)->handle($entity, $fixture, $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('zugferd', $result->document->e_invoice_meta['format']);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame($specification, $result->document->e_invoice_meta['specification_identifier']);
        $this->assertSame($processId, $result->document->e_invoice_meta['business_process_id']);
        $this->assertSame(17250, (int) $result->document->gross_minor);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: ?string}> */
    public static function storedCiiReceptionFixtures(): iterable
    {
        yield 'EN 16931 CII' => ['en16931-s19.xml', 'urn:cen.eu:en16931:2017', null];
        yield 'XRechnung 3 CII' => [
            'xrechnung-3-s19.xml',
            'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
            'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
        ];
    }

    #[Test]
    public function a_generated_xrechnung_cii_invoice_imports_after_schema_and_business_rules(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = app(ZugferdEInvoiceAdapter::class)->generate([
            ...$this->ciiSnapshot(),
            'e_invoice_profile' => 'xrechnung_3',
        ]);

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'cii-xrechnung-3.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('zugferd', $result->document->e_invoice_meta['format']);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertSame(
            'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
            $result->document->e_invoice_meta['specification_identifier'],
        );
        $this->assertSame(
            'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
            $result->document->e_invoice_meta['business_process_id'],
        );
        $this->assertSame(17250, (int) $result->document->gross_minor);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    #[DataProvider('schemaInvalidFixtures')]
    public function invalid_schema_fails_closed_and_preserves_intake(string $directory, string $fixture, string $filename): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->fixture($directory, $fixture);

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, $filename, $xml);
            $this->fail('Schema-invalid e-invoice must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertStringStartsWith(
                $this->schemaInvalidPrefix(),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function schemaInvalidFixtures(): iterable
    {
        yield 'UBL invalid date' => ['ubl', 'schema-invalid-issue-date.xml', 'schema-invalid-issue-date.xml'];
        yield 'UBL missing totals' => ['ubl', 'schema-invalid-missing-totals.xml', 'schema-invalid-missing-totals.xml'];
        yield 'CII missing transaction' => ['cii', 'schema-invalid-missing-transaction.xml', 'schema-invalid-missing-transaction.xml'];
    }

    #[Test]
    #[DataProvider('receptionSubsetInvalidFixtures')]
    public function reception_subset_failures_fail_closed_and_preserve_intake(
        string $fixture,
        string $ruleDetail,
    ): void {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture($fixture);

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, $fixture, $xml);
            $this->fail('Reception-subset invalid e-invoice must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.e_invoice_business_rule_failed', [
                    'detail' => $ruleDetail,
                ]),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function receptionSubsetInvalidFixtures(): iterable
    {
        yield 'missing buyer' => ['br-missing-buyer.xml', 'BR-07: Buyer name (BT-44) is missing'];
        yield 'buyer without address' => ['br-buyer-no-address.xml', 'BR-10: Buyer postal address (BG-8) is missing'];
        yield 'missing VAT breakdown' => ['br-bg23-missing.xml', 'BG-23: VAT breakdown is missing'];
        yield 'VAT breakdown vs totals' => [
            'br-bg23-mismatch.xml',
            'BR-CO-13: sum of VAT category taxable amounts 8000 does not equal TaxExclusiveAmount 10000',
        ];
        yield 'unknown CIUS' => [
            'br-cius-unknown.xml',
            'BR-01: Specification identifier (BT-24) is not an accepted EN 16931 / XRechnung CIUS',
        ];
        yield 'missing CustomizationID' => ['br-cius-missing.xml', 'BR-01: Specification identifier (BT-24) is missing'];
        yield 'XRechnung without ProfileID' => [
            'br-xrechnung-missing-profile.xml',
            'BR-DE-2: Business process type (BT-23) is missing for XRechnung',
        ];
    }

    #[Test]
    public function invalid_business_rule_totals_fail_closed_and_preserve_intake(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('br-invalid-totals.xml');

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'br-invalid-totals.xml', $xml);
            $this->fail('Business-rule invalid e-invoice must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.e_invoice_business_rule_failed', [
                    'detail' => 'BR-CO-15: TaxExclusiveAmount + VAT amount does not equal TaxInclusiveAmount',
                ]),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    #[Test]
    public function allowance_charge_outside_subset_fails_closed_and_preserves_intake(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('document-allowance-unsupported.xml');

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'document-allowance-unsupported.xml', $xml);
            $this->fail('Unsupported allowance/charge must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.unsupported_allowance_charge', [
                    'detail' => 'document-level AllowanceCharge changes net below sum of line nets and cannot be posted',
                ]),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    #[Test]
    public function foreign_vat_fails_closed_and_preserves_intake(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->ublFixture('tax-unknown-21.xml');

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'tax-unknown-21.xml', $xml);
            $this->fail('Foreign VAT must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.unmapped_e_invoice_tax', [
                    'category' => 'S',
                    'rate' => '21%',
                ]),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    #[Test]
    public function schema_gate_rejects_an_invalid_ubl_issue_date_before_parse(): void
    {
        try {
            app(ValidateIncomingEInvoice::class)->assertSchema(
                $this->ublFixture('schema-invalid-issue-date.xml'),
                'ubl',
            );
            $this->fail('Invalid IssueDate must fail schema validation.');
        } catch (DocumentException $exception) {
            $this->assertStringStartsWith($this->schemaInvalidPrefix(), $exception->getMessage());
            $this->assertStringContainsString('IssueDate', $exception->getMessage());
        }
    }

    private function schemaInvalidPrefix(): string
    {
        $template = __('filament-accounting::errors.e_invoice_schema_invalid', ['detail' => 'PLACEHOLDER']);
        $prefix = strstr($template, 'PLACEHOLDER', true);
        $this->assertNotFalse($prefix);

        return $prefix;
    }

    /** @return array<string, mixed> */
    private function ciiSnapshot(): array
    {
        return [
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
    }

    private function ublFixture(string $name): string
    {
        return $this->fixture('ubl', $name);
    }

    private function fixture(string $directory, string $name): string
    {
        $path = dirname(__DIR__).'/Fixtures/'.$directory.'/'.$name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Missing fixture '.$name);
        }

        return $contents;
    }
}

<?php

namespace FilamentAccounting\Tests\Documents;

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
        $this->assertSame('DE-19', $result->document->lines->sole()->tax_code);
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

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
        $this->assertSame('Berlin', $parsed->sellerCity);
        $this->assertSame('10115', $parsed->sellerPostalCode);
        $this->assertSame('DE999999999', $parsed->sellerVatId);
        $this->assertSame('380', $parsed->invoiceTypeCode);
        $this->assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $parsed->customizationId);
        $this->assertSame('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', $parsed->profileId);
        $this->assertSame('BUYER-REF-1', $parsed->buyerReference);
        $this->assertSame('Buchhaltung', $parsed->sellerContactName);
        $this->assertSame('+493012345678', $parsed->sellerContactPhone);
        $this->assertSame('seller@vendor.example', $parsed->sellerContactEmail);
        $this->assertSame('seller@vendor.example', $parsed->sellerElectronicAddress);
        $this->assertSame('EM', $parsed->sellerElectronicAddressScheme);
        $this->assertSame('buyer@customer.example', $parsed->buyerElectronicAddress);
        $this->assertSame('EM', $parsed->buyerElectronicAddressScheme);
        $this->assertSame('58', $parsed->paymentMeans[0]['type_code']);
        $this->assertSame('DE89370400440532013000', $parsed->paymentMeans[0]['payee_iban']);
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
        $parsed = app(UblEInvoiceParser::class)->parse($xml, 'en16931-core-s19.xml');
        $this->assertFalse(filled($parsed->sellerCity));
        $this->assertFalse(filled($parsed->sellerPostalCode));
        $this->assertFalse(filled($parsed->sellerVatId));

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
        $parsed = app(ZugferdEInvoiceAdapter::class)->parse($xml, $fixture);
        if (str_contains($fixture, 'xrechnung')) {
            $this->assertSame('Berlin', $parsed->sellerCity);
            $this->assertSame('10115', $parsed->sellerPostalCode);
            $this->assertSame('DE123456789', $parsed->sellerVatId);
        } else {
            $this->assertFalse(filled($parsed->sellerCity));
            $this->assertFalse(filled($parsed->sellerPostalCode));
            $this->assertFalse(filled($parsed->sellerVatId));
        }

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
        $snapshot = $this->ciiSnapshot();
        $snapshot['e_invoice_profile'] = 'xrechnung_3';
        $snapshot['buyer_reference'] = 'BUYER-REF-1';
        $snapshot['seller']['invoice_contact_name'] = 'Buchhaltung';
        $snapshot['seller']['phone'] = '+493012345678';
        $snapshot['seller']['email'] = 'seller@vendor.example';
        $snapshot['seller']['invoice_iban'] = 'DE89370400440532013000';
        $snapshot['buyer']['email'] = 'buyer@customer.example';
        $xml = app(ZugferdEInvoiceAdapter::class)->generate($snapshot);

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
            'PEPPOL-EN16931-R001: Business process type (BT-23) is missing for XRechnung',
        ];
        yield 'XRechnung missing seller endpoint' => [
            'br-xrechnung-missing-seller-endpoint.xml',
            'PEPPOL-EN16931-R020: Seller electronic address (BT-34) is missing',
        ];
        yield 'XRechnung missing buyer endpoint' => [
            'br-xrechnung-missing-buyer-endpoint.xml',
            'PEPPOL-EN16931-R010: Buyer electronic address (BT-49) is missing',
        ];
        yield 'XRechnung endpoint without scheme' => [
            'br-xrechnung-endpoint-no-scheme.xml',
            'BR-62: Seller electronic address (BT-34) shall have a scheme identifier',
        ];
        yield 'XRechnung missing payment means' => [
            'br-xrechnung-missing-payment-means.xml',
            'BR-DE-13: Payment means (BG-16) is missing',
        ];
        yield 'XRechnung credit transfer without IBAN' => [
            'br-xrechnung-payment-58-no-iban.xml',
            'BR-DE-23: Credit transfer (BG-17) IBAN (BT-84) is missing',
        ];
        yield 'XRechnung missing buyer reference' => [
            'br-xrechnung-missing-buyer-reference.xml',
            'BR-DE-15: Buyer reference (BT-10) is missing',
        ];
        yield 'XRechnung missing seller contact' => [
            'br-xrechnung-missing-seller-contact.xml',
            'BR-DE-2: Seller contact (BG-6) is missing',
        ];
        yield 'XRechnung missing seller city' => [
            'br-xrechnung-missing-seller-city.xml',
            'BR-DE-3: Seller city (BT-37) is missing',
        ];
        yield 'XRechnung missing seller post code' => [
            'br-xrechnung-missing-seller-postcode.xml',
            'BR-DE-4: Seller post code (BT-38) is missing',
        ];
        yield 'XRechnung missing seller VAT identifier' => [
            'br-xrechnung-missing-seller-vat.xml',
            'BR-DE-16: Seller VAT identifier (BT-31) or seller tax representative (BG-11) is missing',
        ];
        yield 'XRechnung blank seller city' => [
            'br-xrechnung-blank-seller-city.xml',
            'BR-DE-3: Seller city (BT-37) is missing',
        ];
        yield 'XRechnung blank seller VAT identifier' => [
            'br-xrechnung-blank-seller-vat.xml',
            'BR-DE-16: Seller VAT identifier (BT-31) or seller tax representative (BG-11) is missing',
        ];
        yield 'XRechnung invalid seller VAT format' => [
            'br-xrechnung-invalid-seller-vat.xml',
            'BR-CO-09: Seller VAT identifier (BT-31) has an invalid format',
        ];
        yield 'XRechnung invalid tax representative VAT format' => [
            'br-xrechnung-invalid-tax-representative-vat.xml',
            'BR-CO-09: Seller tax representative VAT identifier (BT-63) has an invalid format',
        ];
        yield 'XRechnung unknown endpoint scheme' => [
            'br-xrechnung-unknown-eas.xml',
            'BR-CL-25: Seller electronic address (BT-34) scheme identifier is not in the documented EAS subset',
        ];
        yield 'XRechnung incomplete tax representative' => [
            'br-xrechnung-incomplete-tax-representative.xml',
            'BR-DE-16: Seller tax representative VAT identifier (BT-63) is missing',
        ];
    }

    #[Test]
    public function xrechnung_tax_representative_and_documented_eas_scheme_import(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $ubl = $this->ublFixture('xrechnung-bg11-instead-of-seller-vat.xml');
        $parsed = app(UblEInvoiceParser::class)->parse($ubl, 'xrechnung-bg11-instead-of-seller-vat.xml');
        $this->assertFalse(filled($parsed->sellerVatId));
        $this->assertSame('Steuervertretung GmbH', $parsed->sellerTaxRepresentativeName);
        $this->assertSame('DE111111111', $parsed->sellerTaxRepresentativeVatId);
        $this->assertSame('DE', $parsed->sellerTaxRepresentativeCountryCode);
        $ublResult = app(ImportPurchaseInvoice::class)->handle($entity, 'xrechnung-bg11-instead-of-seller-vat.xml', $ubl);
        $this->assertSame('de_eur_subset_passed', $ublResult->document->e_invoice_meta['validation_status']);

        $eas = $this->ublFixture('xrechnung-eas-9930.xml');
        $easParsed = app(UblEInvoiceParser::class)->parse($eas, 'xrechnung-eas-9930.xml');
        $this->assertSame('9930', $easParsed->sellerElectronicAddressScheme);
        $this->assertSame('9930', $easParsed->buyerElectronicAddressScheme);
        $easResult = app(ImportPurchaseInvoice::class)->handle($entity, 'xrechnung-eas-9930.xml', $eas);
        $this->assertSame('de_eur_subset_passed', $easResult->document->e_invoice_meta['validation_status']);

        $cii = $this->fixture('cii', 'xrechnung-bg11-s19.xml');
        $ciiParsed = app(ZugferdEInvoiceAdapter::class)->parse($cii, 'xrechnung-bg11-s19.xml');
        $this->assertFalse(filled($ciiParsed->sellerVatId));
        $this->assertSame('Steuervertretung GmbH', $ciiParsed->sellerTaxRepresentativeName);
        $this->assertSame('DE111111111', $ciiParsed->sellerTaxRepresentativeVatId);
        $this->assertSame('DE', $ciiParsed->sellerTaxRepresentativeCountryCode);
        $ciiResult = app(ImportPurchaseInvoice::class)->handle($entity, 'xrechnung-bg11-s19.xml', $cii);
        $this->assertSame('de_eur_subset_passed', $ciiResult->document->e_invoice_meta['validation_status']);
        $this->assertSame(3, PurchaseInvoiceIntake::query()->where('status', 'complete')->count());
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

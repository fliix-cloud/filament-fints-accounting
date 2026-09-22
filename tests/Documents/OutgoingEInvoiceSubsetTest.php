<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Documents\ValidateIncomingEInvoice;
use FilamentAccounting\Documents\ValidateOutgoingEInvoice;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Enums\PartyAddressRole;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Tests\TestCase;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class OutgoingEInvoiceSubsetTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function en16931_issuing_does_not_require_buyer_reference_or_seller_contact(): void
    {
        $document = $this->issuedInvoice();
        $snapshot = app(GenerateInvoiceArtifacts::class)->snapshot($document);

        app(ValidateOutgoingEInvoice::class)->assert($snapshot);

        $snapshot['e_invoice_profile'] = 'xrechnung_3';
        $snapshot['seller']['invoice_contact_name'] = 'Buchhaltung';
        $snapshot['seller']['phone'] = '+493012345678';
        $snapshot['seller']['email'] = 'seller@vendor.example';
        $snapshot['seller']['invoice_iban'] = 'DE89370400440532013000';
        $snapshot['buyer']['email'] = 'buyer@customer.example';
        $snapshot['buyer']['addresses'] = [[
            'line1' => 'Kundenstr. 2',
            'postal_code' => '80331',
            'city' => 'München',
            'country_code' => 'DE',
        ]];
        try {
            app(ValidateOutgoingEInvoice::class)->assert($snapshot);
            $this->fail('XRechnung without a buyer reference must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString('BR-DE-15: Buyer reference (BT-10) is missing', $exception->getMessage());
        }
        $this->assertSame(0, InvoiceArtifactSet::query()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    #[Test]
    public function missing_seller_city_or_vat_does_not_store_originals(): void
    {
        $withoutCity = $this->issuedInvoice(['city' => null]);
        try {
            app(GenerateInvoiceArtifacts::class)->handle($withoutCity);
            $this->fail('Missing seller city must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString('BR-DE-3: Seller city (BT-37) is missing', $exception->getMessage());
        }

        $withoutVat = $this->issuedInvoice(['vat_id' => null]);
        try {
            app(GenerateInvoiceArtifacts::class)->handle($withoutVat);
            $this->fail('Missing seller VAT identifier must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString('BR-DE-16: Seller VAT identifier (BT-31) is missing', $exception->getMessage());
        }

        $this->assertSame(0, InvoiceArtifactSet::query()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    #[Test]
    public function foreign_currency_is_rejected_before_originals_are_stored(): void
    {
        $document = $this->issuedInvoice();
        $snapshot = app(GenerateInvoiceArtifacts::class)->snapshot($document);
        $snapshot['currency'] = 'USD';

        try {
            app(ValidateOutgoingEInvoice::class)->assert($snapshot);
            $this->fail('Foreign currency must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString('BR-05: Invoice currency code (BT-5) must be EUR', $exception->getMessage());
        }
        $this->assertSame(0, Attachment::query()->count());
    }

    #[Test]
    public function xrechnung_profile_round_trips_through_the_reception_gate(): void
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.default_profile', 'xrechnung_3');
        $document = $this->issuedInvoice([
            'invoice_contact_name' => 'Buchhaltung',
            'phone' => '+493012345678',
            'email' => 'seller@vendor.example',
            'invoice_iban' => 'DE89370400440532013000',
        ], buyerAddress: true, buyerReference: 'BUYER-REF-1');

        $artifacts = app(GenerateInvoiceArtifacts::class)->handle($document);
        $xml = app(ReadAttachment::class)->handle($artifacts['xml']);
        $pdf = app(ReadAttachment::class)->handle($artifacts['pdf']);
        $embedded = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($pdf);

        $this->assertSame($xml, $embedded);
        $incoming = app(ValidateIncomingEInvoice::class);
        $parsed = app(ZugferdEInvoiceAdapter::class)->parse($xml, 'issued.xml');
        $incoming->assertSchema($xml, 'zugferd');
        $incoming->assertBusinessRules($parsed);
        $incoming->assertBusinessRules(app(ZugferdEInvoiceAdapter::class)->parse($embedded, 'embedded.xml'));
        $this->assertSame('BUYER-REF-1', $parsed->buyerReference);
        $this->assertSame('Buchhaltung', $parsed->sellerContactName);
        $this->assertSame('Berlin', $parsed->sellerCity);
        $this->assertSame('10115', $parsed->sellerPostalCode);
        $this->assertSame('DE123456789', $parsed->sellerVatId);
        $this->assertStringContainsString('xrechnung_3.0', (string) $parsed->customizationId);
        $this->assertSame('de_eur_subset_passed', $artifacts['xml']->meta['validation_status']);
        $this->assertSame('xrechnung_3', $artifacts['xml']->meta['profile']);
        $this->assertStringNotContainsString('certified', strtolower(json_encode($artifacts['xml']->meta, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function xrechnung_without_buyer_reference_does_not_store_originals(): void
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.default_profile', 'xrechnung_3');
        $document = $this->issuedInvoice([
            'invoice_contact_name' => 'Buchhaltung',
            'phone' => '+493012345678',
            'email' => 'seller@vendor.example',
            'invoice_iban' => 'DE89370400440532013000',
        ], buyerAddress: true);

        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('XRechnung without a buyer reference must not store originals.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString('BR-DE-15: Buyer reference (BT-10) is missing', $exception->getMessage());
        }
        $this->assertSame(0, InvoiceArtifactSet::query()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    /** @param  array<string, mixed>  $seller */
    private function issuedInvoice(array $seller = [], bool $buyerAddress = false, ?string $buyerReference = null): Document
    {
        config()->set('filament-accounting.e_invoice.generate_on_issue', false);
        $entity = $this->makeEntity($seller + [
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country_code' => 'DE',
            'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser('user-'.uniqid('', true).'@example.com'));
        $party = $this->makeParty($entity, [
            'external_reference' => $buyerReference,
            'email' => $buyerAddress ? 'buyer@customer.example' : null,
        ]);
        if ($buyerAddress) {
            PartyAddress::query()->create([
                'party_id' => $party->getKey(),
                'line1' => 'Kundenstr. 2',
                'postal_code' => '80331',
                'city' => 'München',
                'country_code' => 'DE',
                'address_role' => PartyAddressRole::Billing,
                'is_primary' => true,
            ]);
        }

        return app(IssueSalesInvoice::class)->issue(app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $party->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19']],
        ]), false);
    }
}

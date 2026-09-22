<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Tax\MapImportedEInvoiceTax;

/**
 * Fail-closed DE-EUR issuing subset for outbound Factur-X CII and XRechnung UBL.
 *
 * Runs on the frozen sales-invoice snapshot before XML or PDF bytes are created.
 * XRechnung CIUS extras apply to xrechnung_3 (CII) and xrechnung_3_ubl (UBL).
 * This is not an EN 16931, XRechnung, or ZUGFeRD certification.
 */
final class ValidateOutgoingEInvoice
{
    public const PROFILE_EN16931 = 'en16931';

    public const PROFILE_XRECHNUNG_3 = 'xrechnung_3';

    public const PROFILE_XRECHNUNG_3_UBL = 'xrechnung_3_ubl';

    /** @var list<string> */
    private const SELLER_VAT_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G', 'L', 'M'];

    public function __construct(
        private readonly MapImportedEInvoiceTax $taxMapping,
    ) {}

    public function profile(?string $configured = null): string
    {
        $profile = strtolower(trim($configured ?? (string) config('filament-accounting.e_invoice.default_profile', self::PROFILE_EN16931)));
        if (! in_array($profile, [self::PROFILE_EN16931, self::PROFILE_XRECHNUNG_3, self::PROFILE_XRECHNUNG_3_UBL], true)) {
            throw $this->failed('Issuing profile is not in the documented DE-EUR subset');
        }

        return $profile;
    }

    public function isUbl(string $profile): bool
    {
        return $profile === self::PROFILE_XRECHNUNG_3_UBL;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function assert(array $snapshot): void
    {
        $profile = $this->profile(isset($snapshot['e_invoice_profile']) ? (string) $snapshot['e_invoice_profile'] : null);
        $seller = is_array($snapshot['seller'] ?? null) ? $snapshot['seller'] : [];
        $buyer = is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [];
        $payment = is_array($snapshot['payment'] ?? null) ? $snapshot['payment'] : [];
        $lines = is_array($snapshot['lines'] ?? null) ? array_values($snapshot['lines']) : [];

        $this->assertHeader($snapshot);
        $this->assertSeller($seller, $lines);
        $this->assertBuyer($buyer, $profile);
        $this->assertLines($snapshot, $lines);
        $this->assertPayment($seller, $payment, $profile);
        if ($this->isXrechnung($profile)) {
            $this->assertXrechnungParties($snapshot, $seller, $buyer);
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    private function assertHeader(array $snapshot): void
    {
        if (trim((string) ($snapshot['number'] ?? '')) === '') {
            throw $this->failed('BR-02: Invoice number (BT-1) is missing');
        }
        if (trim((string) ($snapshot['issue_date'] ?? '')) === '') {
            throw $this->failed('BR-03: Invoice issue date (BT-2) is missing');
        }
        $currency = strtoupper(trim((string) ($snapshot['currency'] ?? '')));
        if ($currency === '') {
            throw $this->failed('BR-05: Invoice currency code (BT-5) is missing');
        }
        if ($currency !== 'EUR') {
            throw $this->failed('BR-05: Invoice currency code (BT-5) must be EUR');
        }
    }

    /**
     * @param  array<string, mixed>  $seller
     * @param  list<mixed>  $lines
     */
    private function assertSeller(array $seller, array $lines): void
    {
        if (! filled($seller['legal_name'] ?? null)) {
            throw $this->failed('BR-08: Seller name (BT-27) is missing');
        }
        if (! filled($seller['city'] ?? null)) {
            throw $this->failed('BR-DE-3: Seller city (BT-37) is missing');
        }
        if (! filled($seller['postal_code'] ?? null)) {
            throw $this->failed('BR-DE-4: Seller post code (BT-38) is missing');
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper(trim((string) ($seller['country_code'] ?? '')))) !== 1) {
            throw $this->failed('BR-09: Seller country code (BT-40) is missing');
        }
        if (filled($seller['vat_id'] ?? null) && ! EInvoiceSubsetCatalog::vatIdentifierIsValid((string) $seller['vat_id'])) {
            throw $this->failed('BR-CO-09: Seller VAT identifier (BT-31) has an invalid format');
        }
        $this->assertTaxRepresentative($seller);
        if ($this->requiresSellerVat($lines) && ! filled($seller['vat_id'] ?? null) && ! $this->hasCompleteTaxRepresentative($seller)) {
            throw $this->failed('BR-DE-16: Seller VAT identifier (BT-31) or seller tax representative (BG-11) is missing');
        }
    }

    /** @param  array<string, mixed>  $seller */
    private function assertTaxRepresentative(array $seller): void
    {
        $name = $seller['tax_representative_name'] ?? null;
        $vatId = $seller['tax_representative_vat_id'] ?? null;
        $country = strtoupper(trim((string) ($seller['tax_representative_country_code'] ?? '')));
        if (! filled($name) && ! filled($vatId) && $country === '') {
            return;
        }
        if (! filled($name)) {
            throw $this->failed('BR-18: Seller tax representative name (BT-62) is missing');
        }
        if (! filled($vatId)) {
            throw $this->failed('BR-DE-16: Seller tax representative VAT identifier (BT-63) is missing');
        }
        if (! EInvoiceSubsetCatalog::vatIdentifierIsValid((string) $vatId)) {
            throw $this->failed('BR-CO-09: Seller tax representative VAT identifier (BT-63) has an invalid format');
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw $this->failed('BR-20: Seller tax representative country code (BT-70) is missing');
        }
    }

    /** @param  array<string, mixed>  $seller */
    private function hasCompleteTaxRepresentative(array $seller): bool
    {
        return filled($seller['tax_representative_name'] ?? null)
            && filled($seller['tax_representative_vat_id'] ?? null)
            && EInvoiceSubsetCatalog::vatIdentifierIsValid((string) $seller['tax_representative_vat_id'])
            && preg_match('/^[A-Z]{2}$/', strtoupper(trim((string) ($seller['tax_representative_country_code'] ?? '')))) === 1;
    }

    /** @param  array<string, mixed>  $buyer */
    private function assertBuyer(array $buyer, string $profile): void
    {
        if (! filled($buyer['legal_name'] ?? null)) {
            throw $this->failed('BR-07: Buyer name (BT-44) is missing');
        }

        $address = $this->buyerAddress($buyer);
        $country = strtoupper(trim((string) ($address['country_code'] ?? $buyer['country_code'] ?? '')));
        $hasPostal = filled($address['line1'] ?? null)
            || filled($address['postal_code'] ?? null)
            || filled($address['city'] ?? null)
            || $country !== '';
        if (! $hasPostal) {
            throw $this->failed('BR-10: Buyer postal address (BG-8) is missing');
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw $this->failed('BR-11: Buyer country code (BT-55) is missing');
        }
        if ($this->isXrechnung($profile)) {
            if (! filled($address['city'] ?? null)) {
                throw $this->failed('BR-DE-8: Buyer city (BT-52) is missing');
            }
            if (! filled($address['postal_code'] ?? null)) {
                throw $this->failed('BR-DE-9: Buyer post code (BT-53) is missing');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<mixed>  $lines
     */
    private function assertLines(array $snapshot, array $lines): void
    {
        if ($lines === []) {
            throw $this->failed('BR-16: Invoice has no lines');
        }

        $sumNet = 0;
        $sumTax = 0;
        /** @var array<string, array{taxable: int, tax: int, rate_bp: int}> $groups */
        $groups = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw $this->failed('BR-16: Invoice has no lines');
            }
            $net = (int) ($line['net_minor'] ?? 0);
            $tax = (int) ($line['tax_minor'] ?? 0);
            $rateBp = (int) ($line['tax_rate_bp'] ?? 0);
            $untDid = ZugferdEInvoiceAdapter::untDidTaxCategory((string) ($line['tax_category'] ?? 'standard'), $rateBp);
            $this->taxMapping->code($rateBp, $untDid);
            $sumNet += $net;
            $sumTax += $tax;
            $key = $untDid.'|'.$rateBp;
            $groups[$key] ??= ['taxable' => 0, 'tax' => 0, 'rate_bp' => $rateBp];
            $groups[$key]['taxable'] += $net;
            $groups[$key]['tax'] += $tax;
        }

        $documentNet = (int) ($snapshot['net_minor'] ?? 0);
        $documentTax = (int) ($snapshot['tax_minor'] ?? 0);
        $documentGross = (int) ($snapshot['gross_minor'] ?? 0);
        if ($sumNet !== $documentNet) {
            throw $this->failed("BR-CO-10: sum of line nets {$sumNet} does not equal TaxExclusiveAmount {$documentNet}");
        }
        if ($documentNet + $documentTax !== $documentGross) {
            throw $this->failed('BR-CO-15: TaxExclusiveAmount + VAT amount does not equal TaxInclusiveAmount');
        }
        if ($sumTax !== $documentTax) {
            throw $this->failed("BR-CO-17: VAT amount {$documentTax} does not equal the sum of line VAT {$sumTax}");
        }
        foreach ($groups as $group) {
            $expectedTax = LineMoneyCalculator::taxMinor($group['taxable'], $group['rate_bp']);
            if ($expectedTax !== $group['tax']) {
                throw $this->failed('BG-23: VAT category tax amount does not equal taxable amount × rate');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $payment
     */
    private function assertPayment(array $seller, array $payment, string $profile): void
    {
        $method = (string) ($payment['method'] ?? 'credit_transfer');
        if ($method === 'direct_debit') {
            if (! filled($payment['debtor_iban'] ?? null)
                || ! filled($payment['mandate_reference'] ?? null)
                || ! filled($payment['creditor_identifier'] ?? null)) {
                throw new DocumentException(__('filament-accounting::invoice.mandate_required'));
            }

            return;
        }
        if ($method !== 'credit_transfer') {
            throw $this->failed('BR-DE-13: Payment means type code (BT-81) is not an accepted issuing code');
        }
        if ($this->isXrechnung($profile) && ! filled($seller['invoice_iban'] ?? null)) {
            throw $this->failed('BR-DE-23: Credit transfer (BG-17) IBAN (BT-84) is missing');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     */
    private function assertXrechnungParties(array $snapshot, array $seller, array $buyer): void
    {
        $reference = trim((string) ($snapshot['buyer_reference'] ?? $buyer['buyer_reference'] ?? $buyer['external_reference'] ?? ''));
        if ($reference === '') {
            throw $this->failed('BR-DE-15: Buyer reference (BT-10) is missing');
        }
        $name = trim((string) ($seller['invoice_contact_name'] ?? $seller['contact_name'] ?? ''));
        $phone = trim((string) ($seller['phone'] ?? ''));
        $email = trim((string) ($seller['email'] ?? ''));
        if ($name === '' && $phone === '' && $email === '') {
            throw $this->failed('BR-DE-2: Seller contact (BG-6) is missing');
        }
        if ($name === '') {
            throw $this->failed('BR-DE-5: Seller contact point (BT-41) is missing');
        }
        if ($phone === '') {
            throw $this->failed('BR-DE-6: Seller contact telephone number (BT-42) is missing');
        }
        if ($email === '') {
            throw $this->failed('BR-DE-7: Seller contact email address (BT-43) is missing');
        }
        $sellerEndpoint = trim((string) ($seller['electronic_address'] ?? $seller['email'] ?? ''));
        $buyerEndpoint = trim((string) ($buyer['electronic_address'] ?? $buyer['invoice_email'] ?? $buyer['email'] ?? ''));
        $sellerScheme = strtoupper(trim((string) ($seller['electronic_address_scheme'] ?? 'EM')));
        $buyerScheme = strtoupper(trim((string) ($buyer['electronic_address_scheme'] ?? 'EM')));
        if ($sellerEndpoint === '') {
            throw $this->failed('PEPPOL-EN16931-R020: Seller electronic address (BT-34) is missing');
        }
        if ($sellerScheme === '') {
            throw $this->failed('BR-62: Seller electronic address (BT-34) shall have a scheme identifier');
        }
        if (! EInvoiceSubsetCatalog::easSchemeAllowed($sellerScheme)) {
            throw $this->failed('BR-CL-25: Seller electronic address (BT-34) scheme identifier is not in the documented EAS subset');
        }
        if ($buyerEndpoint === '') {
            throw $this->failed('PEPPOL-EN16931-R010: Buyer electronic address (BT-49) is missing');
        }
        if ($buyerScheme === '') {
            throw $this->failed('BR-62: Buyer electronic address (BT-49) shall have a scheme identifier');
        }
        if (! EInvoiceSubsetCatalog::easSchemeAllowed($buyerScheme)) {
            throw $this->failed('BR-CL-25: Buyer electronic address (BT-49) scheme identifier is not in the documented EAS subset');
        }
    }

    private function isXrechnung(string $profile): bool
    {
        return in_array($profile, [self::PROFILE_XRECHNUNG_3, self::PROFILE_XRECHNUNG_3_UBL], true);
    }

    /** @param  list<mixed>  $lines */
    private function requiresSellerVat(array $lines): bool
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rateBp = (int) ($line['tax_rate_bp'] ?? 0);
            $untDid = ZugferdEInvoiceAdapter::untDidTaxCategory((string) ($line['tax_category'] ?? 'standard'), $rateBp);
            if (in_array($untDid, self::SELLER_VAT_CATEGORIES, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $buyer
     * @return array<string, mixed>
     */
    private function buyerAddress(array $buyer): array
    {
        $addresses = $buyer['addresses'] ?? null;
        if (! is_array($addresses) || ! is_array($addresses[0] ?? null)) {
            return [];
        }

        return $addresses[0];
    }

    private function failed(string $detail): DocumentException
    {
        return new DocumentException(__('filament-accounting::errors.e_invoice_issuing_subset_failed', [
            'detail' => $detail,
        ]));
    }
}

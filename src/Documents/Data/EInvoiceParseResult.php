<?php

namespace FilamentAccounting\Documents\Data;

final readonly class EInvoiceParseResult
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $meta
     * @param  list<array{category: string, rate_bp: int|null, taxable_minor: int, tax_minor: int}>  $vatBreakdown
     * @param  list<array{type_code: string, payee_iban: ?string, debtor_iban: ?string}>  $paymentMeans
     */
    public function __construct(
        public string $formatKey,
        public string $documentNumber,
        public ?string $issueDate,
        public string $currency,
        public int $grossMinor,
        public int $netMinor,
        public int $taxMinor,
        public ?string $sellerName,
        public ?string $sellerVatId,
        public array $lines,
        public string $originalXml,
        public string $sha256,
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
        public array $meta = [],
        public ?string $sellerAddressLine1 = null,
        public ?string $sellerAddressLine2 = null,
        public ?string $sellerPostalCode = null,
        public ?string $sellerCity = null,
        public ?string $sellerCountryCode = null,
        public ?string $sellerEmail = null,
        public ?string $invoiceTypeCode = null,
        public ?string $customizationId = null,
        public ?string $profileId = null,
        public ?string $buyerName = null,
        public ?string $buyerAddressLine1 = null,
        public ?string $buyerPostalCode = null,
        public ?string $buyerCity = null,
        public ?string $buyerCountryCode = null,
        public array $vatBreakdown = [],
        public ?string $sellerElectronicAddress = null,
        public ?string $sellerElectronicAddressScheme = null,
        public ?string $buyerElectronicAddress = null,
        public ?string $buyerElectronicAddressScheme = null,
        public array $paymentMeans = [],
        public ?string $buyerReference = null,
        public ?string $sellerContactName = null,
        public ?string $sellerContactPhone = null,
        public ?string $sellerContactEmail = null,
        public ?string $sellerTaxRepresentativeName = null,
        public ?string $sellerTaxRepresentativeVatId = null,
        public ?string $sellerTaxRepresentativeCountryCode = null,
    ) {}
}

<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Tax\MapImportedEInvoiceTax;
use horstoeko\zugferd\exception\ZugferdUnknownProfileException;
use horstoeko\zugferd\exception\ZugferdUnknownXmlContentException;
use horstoeko\zugferd\ZugferdProfileResolver;
use horstoeko\zugferd\ZugferdSettings;

/**
 * Fail-closed schema + DE-EUR EN 16931 reception subset for incoming e-invoices.
 *
 * This is a documented subset gate (schema + material BRs including buyer,
 * BG-23, CIUS identifiers, XRechnung electronic addresses, and payment means).
 * It is not a full Schematron / XRechnung / ZUGFeRD certification engine.
 */
final class ValidateIncomingEInvoice
{
    /** @var list<string> */
    private const INVOICE_TYPE_CODES = ['380', '381', '384', '389', '326', '261', '382', '386', '875', '876', '877'];

    /** @var list<string> */
    private const ACCEPTED_CIUS = [
        'urn:xeinkauf.de:kosit:xrechnung_3.0',
        'urn:xoev-de:kosit:standard:xrechnung_2.3',
        'urn:xoev-de:kosit:standard:xrechnung_2.2',
        'urn:xoev-de:kosit:standard:xrechnung_2.1',
        'urn:xoev-de:kosit:standard:xrechnung_2.0',
        'urn:xoev-de:kosit:standard:xrechnung_1.2',
        'urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        'urn:factur-x.eu:1p0:en16931',
        'urn:factur-x.eu:1p0:basic',
        'urn:zugferd.de:2p0:en16931',
        'urn:zugferd.de:2p0:basic',
    ];

    private const EN16931_SPEC = 'urn:cen.eu:en16931:2017';

    private const PEPPOL_BILLING_PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    /** @var list<string> */
    private const XR_PAYMENT_MEANS_CODES = ['30', '48', '49', '54', '57', '58', '59'];

    /** @var list<string> */
    private const CREDIT_TRANSFER_CODES = ['30', '58'];

    /** @var list<string> */
    private const DIRECT_DEBIT_CODES = ['49', '59'];

    public function __construct(
        private readonly MapImportedEInvoiceTax $taxMapping,
    ) {}

    public function assertSchema(string $contents, string $formatKey): void
    {
        $document = $this->loadXml($contents);

        match ($formatKey) {
            'ubl' => $this->assertUblSchema($document),
            'zugferd' => $this->assertCiiSchema($contents, $document),
            default => throw new DocumentException(__('filament-accounting::errors.invalid_e_invoice')),
        };
    }

    public function assertBusinessRules(EInvoiceParseResult $parsed): void
    {
        if ($parsed->documentNumber === '') {
            throw $this->businessRule('BR-02', 'Invoice number (BT-1) is missing');
        }
        if ($parsed->issueDate === null || $parsed->issueDate === '') {
            throw $this->businessRule('BR-03', 'Invoice issue date (BT-2) is missing');
        }
        if (trim($parsed->currency) === '') {
            throw $this->businessRule('BR-05', 'Invoice currency code (BT-5) is missing');
        }
        if (strtoupper($parsed->currency) !== 'EUR') {
            throw new DocumentException(__('filament-accounting::errors.foreign_currency_unsupported'));
        }
        if (! filled($parsed->sellerName)) {
            throw $this->businessRule('BR-08', 'Seller name (BT-27) is missing');
        }
        if ($parsed->lines === []) {
            throw $this->businessRule('BR-16', 'Invoice has no lines');
        }

        $this->assertInvoiceTypeCode($parsed->invoiceTypeCode);
        $this->assertBuyer($parsed);
        $this->assertSpecification($parsed->customizationId, $parsed->profileId);
        $this->assertElectronicAddresses($parsed);
        $this->assertXrechnungPaymentMeans($parsed);

        $sumLineNets = 0;
        $computedTax = 0;
        foreach ($parsed->lines as $line) {
            $net = $this->lineNetMinor($line);
            $sumLineNets += $net;
            $rate = array_key_exists('tax_rate_bp', $line) && $line['tax_rate_bp'] !== null
                ? (int) $line['tax_rate_bp']
                : null;
            $category = filled($line['tax_category'] ?? null) ? (string) $line['tax_category'] : null;
            $this->taxMapping->code($rate, $category);
            $computedTax += LineMoneyCalculator::taxMinor($net, $rate ?? 0);
        }

        if ($sumLineNets !== $parsed->netMinor) {
            throw $this->businessRule(
                'BR-CO-10',
                "sum of line nets {$sumLineNets} does not equal TaxExclusiveAmount {$parsed->netMinor}",
            );
        }

        if ($parsed->netMinor + $parsed->taxMinor !== $parsed->grossMinor) {
            throw $this->businessRule(
                'BR-CO-15',
                'TaxExclusiveAmount + VAT amount does not equal TaxInclusiveAmount',
            );
        }

        if ($computedTax !== $parsed->taxMinor) {
            throw $this->businessRule(
                'BR-CO-17',
                "VAT amount {$parsed->taxMinor} does not equal the sum of line VAT {$computedTax}",
            );
        }

        $this->assertVatBreakdown($parsed);
    }

    private function assertInvoiceTypeCode(?string $typeCode): void
    {
        $code = trim((string) $typeCode);
        if ($code === '') {
            throw $this->businessRule('BR-04', 'Invoice type code (BT-3) is missing');
        }
        if (! in_array($code, self::INVOICE_TYPE_CODES, true)) {
            throw $this->businessRule('BR-04', 'Invoice type code (BT-3) is not an accepted document type');
        }
    }

    private function assertBuyer(EInvoiceParseResult $parsed): void
    {
        if (! filled($parsed->buyerName)) {
            throw $this->businessRule('BR-07', 'Buyer name (BT-44) is missing');
        }

        $country = strtoupper(trim((string) $parsed->buyerCountryCode));
        $hasPostal = filled($parsed->buyerAddressLine1)
            || filled($parsed->buyerPostalCode)
            || filled($parsed->buyerCity)
            || $country !== '';
        if (! $hasPostal) {
            throw $this->businessRule('BR-10', 'Buyer postal address (BG-8) is missing');
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw $this->businessRule('BR-11', 'Buyer country code (BT-55) is missing');
        }

        if ($this->isXrechnung($parsed->customizationId)) {
            if (! filled($parsed->buyerCity)) {
                throw $this->businessRule('BR-DE-8', 'Buyer city (BT-52) is missing');
            }
            if (! filled($parsed->buyerPostalCode)) {
                throw $this->businessRule('BR-DE-9', 'Buyer post code (BT-53) is missing');
            }
        }
    }

    private function assertSpecification(?string $customizationId, ?string $profileId): void
    {
        $spec = strtolower(trim((string) $customizationId));
        if ($spec === '') {
            throw $this->businessRule('BR-01', 'Specification identifier (BT-24) is missing');
        }
        if (! $this->isAcceptedSpecification($spec)) {
            throw $this->businessRule(
                'BR-01',
                'Specification identifier (BT-24) is not an accepted EN 16931 / XRechnung CIUS',
            );
        }

        $profile = strtolower(trim((string) $profileId));
        if ($this->isXrechnung($spec) && $profile === '') {
            throw $this->businessRule('BR-DE-2', 'Business process type (BT-23) is missing for XRechnung');
        }
        if ($profile !== '' && $profile !== self::PEPPOL_BILLING_PROCESS) {
            throw $this->businessRule(
                'BT-23',
                'Business process type (BT-23) is not an accepted reception process identifier',
            );
        }
    }

    private function isAcceptedSpecification(string $spec): bool
    {
        if ($spec === self::EN16931_SPEC) {
            return true;
        }

        $prefix = self::EN16931_SPEC.'#compliant#';
        if (! str_starts_with($spec, $prefix)) {
            return false;
        }

        $cius = explode('#conformant#', substr($spec, strlen($prefix)), 2)[0];

        return in_array($cius, self::ACCEPTED_CIUS, true);
    }

    private function isXrechnung(?string $customizationId): bool
    {
        return str_contains(strtolower((string) $customizationId), 'xrechnung');
    }

    private function requiresElectronicAddresses(?string $customizationId): bool
    {
        $spec = strtolower((string) $customizationId);

        return $this->isXrechnung($spec)
            || str_contains($spec, 'urn:fdc:peppol.eu:2017:poacc:billing:3.0');
    }

    private function assertElectronicAddresses(EInvoiceParseResult $parsed): void
    {
        if (! $this->requiresElectronicAddresses($parsed->customizationId)) {
            return;
        }

        $this->assertElectronicAddress(
            'PEPPOL-EN16931-R020',
            'Seller electronic address (BT-34)',
            $parsed->sellerElectronicAddress,
            $parsed->sellerElectronicAddressScheme,
        );
        $this->assertElectronicAddress(
            'PEPPOL-EN16931-R010',
            'Buyer electronic address (BT-49)',
            $parsed->buyerElectronicAddress,
            $parsed->buyerElectronicAddressScheme,
        );
    }

    private function assertElectronicAddress(string $rule, string $label, ?string $value, ?string $scheme): void
    {
        if (! filled($value)) {
            throw $this->businessRule($rule, $label.' is missing');
        }
        if (! filled($scheme)) {
            throw $this->businessRule('BR-62', $label.' shall have a scheme identifier');
        }
    }

    private function assertXrechnungPaymentMeans(EInvoiceParseResult $parsed): void
    {
        if (! $this->isXrechnung($parsed->customizationId)) {
            return;
        }

        if ($parsed->paymentMeans === []) {
            throw $this->businessRule('BR-DE-13', 'Payment means (BG-16) is missing');
        }

        foreach ($parsed->paymentMeans as $means) {
            $code = trim($means['type_code']);
            if ($code === '') {
                throw $this->businessRule('BR-DE-13', 'Payment means type code (BT-81) is missing');
            }
            if (! in_array($code, self::XR_PAYMENT_MEANS_CODES, true)) {
                throw $this->businessRule(
                    'BR-DE-13',
                    'Payment means type code (BT-81) is not an accepted XRechnung code',
                );
            }
            if (in_array($code, self::CREDIT_TRANSFER_CODES, true) && ! filled($means['payee_iban'])) {
                throw $this->businessRule('BR-DE-23', 'Credit transfer (BG-17) IBAN (BT-84) is missing');
            }
            if (in_array($code, self::DIRECT_DEBIT_CODES, true) && ! filled($means['debtor_iban'])) {
                throw $this->businessRule('BR-DE-25', 'Direct debit (BG-19) IBAN (BT-91) is missing');
            }
        }
    }

    private function assertVatBreakdown(EInvoiceParseResult $parsed): void
    {
        if ($parsed->vatBreakdown === []) {
            throw $this->businessRule('BG-23', 'VAT breakdown is missing');
        }

        $sumTaxable = 0;
        $sumTax = 0;
        /** @var array<string, array{taxable: int, tax: int}> $groups */
        $groups = [];
        foreach ($parsed->vatBreakdown as $group) {
            $category = strtoupper(trim($group['category']));
            if ($category === '') {
                throw $this->businessRule('BG-23', 'VAT category code (BT-118) is missing');
            }

            $rateBp = $group['rate_bp'];
            $taxable = $group['taxable_minor'];
            $tax = $group['tax_minor'];
            $this->taxMapping->code($rateBp, $category);

            $key = $category.'|'.($rateBp === null ? 'none' : (string) $rateBp);
            if (array_key_exists($key, $groups)) {
                throw $this->businessRule('BG-23', 'duplicate VAT breakdown for category '.$category);
            }

            $expectedTax = LineMoneyCalculator::taxMinor($taxable, $rateBp ?? 0);
            if ($expectedTax !== $tax) {
                throw $this->businessRule(
                    'BG-23',
                    "VAT category tax amount {$tax} does not equal taxable amount {$taxable} × rate",
                );
            }

            $groups[$key] = ['taxable' => $taxable, 'tax' => $tax];
            $sumTaxable += $taxable;
            $sumTax += $tax;
        }

        if ($sumTaxable !== $parsed->netMinor) {
            throw $this->businessRule(
                'BR-CO-13',
                "sum of VAT category taxable amounts {$sumTaxable} does not equal TaxExclusiveAmount {$parsed->netMinor}",
            );
        }
        if ($sumTax !== $parsed->taxMinor) {
            throw $this->businessRule(
                'BR-CO-14',
                "sum of VAT category tax amounts {$sumTax} does not equal invoice VAT amount {$parsed->taxMinor}",
            );
        }

        $lineGroups = [];
        foreach ($parsed->lines as $line) {
            $category = strtoupper(trim((string) ($line['tax_category'] ?? '')));
            $rateBp = array_key_exists('tax_rate_bp', $line) && $line['tax_rate_bp'] !== null
                ? (int) $line['tax_rate_bp']
                : null;
            $key = $category.'|'.($rateBp === null ? 'none' : (string) $rateBp);
            $lineGroups[$key] = ($lineGroups[$key] ?? 0) + $this->lineNetMinor($line);
        }

        foreach ($lineGroups as $key => $lineTaxable) {
            if (! array_key_exists($key, $groups) || $groups[$key]['taxable'] !== $lineTaxable) {
                throw $this->businessRule('BG-23', 'VAT breakdown taxable amounts do not match invoice lines');
            }
        }
        foreach ($groups as $key => $group) {
            if (! array_key_exists($key, $lineGroups)) {
                throw $this->businessRule('BG-23', 'VAT breakdown taxable amounts do not match invoice lines');
            }
        }
    }

    private function assertUblSchema(\DOMDocument $document): void
    {
        if ($document->documentElement?->localName !== 'Invoice') {
            throw $this->schemaInvalid('root element must be Invoice');
        }

        $this->schemaValidate($document, $this->ublInvoiceSchemaPath());

        $xpath = new \DOMXPath($document);
        $requiredNodes = ['ID', 'IssueDate', 'DocumentCurrencyCode', 'AccountingSupplierParty', 'LegalMonetaryTotal', 'InvoiceLine'];
        $requiredText = ['ID', 'IssueDate', 'DocumentCurrencyCode'];
        foreach ($requiredNodes as $name) {
            $expression = "/*[local-name()='Invoice']/*[local-name()='{$name}']";
            $nodes = $xpath->query($expression);
            if ($nodes === false || $nodes->length === 0) {
                throw $this->schemaInvalid('required element '.$name.' is missing');
            }
            if (in_array($name, $requiredText, true) && trim((string) $xpath->evaluate('string('.$expression.')')) === '') {
                throw $this->schemaInvalid('required element '.$name.' is missing');
            }
        }
    }

    private function assertCiiSchema(string $contents, \DOMDocument $document): void
    {
        if ($document->documentElement?->localName !== 'CrossIndustryInvoice') {
            throw $this->schemaInvalid('root element must be CrossIndustryInvoice');
        }

        try {
            $profile = ZugferdProfileResolver::resolveProfileDef($contents);
        } catch (ZugferdUnknownProfileException|ZugferdUnknownXmlContentException $exception) {
            throw $this->schemaInvalid('CII profile could not be resolved', $exception);
        }

        $xsd = ZugferdSettings::getSchemaDirectory().DIRECTORY_SEPARATOR.$profile['xsdfilename'];
        if (! is_file($xsd)) {
            throw $this->schemaInvalid('CII schema file is not available');
        }

        $this->schemaValidate($document, $xsd);
    }

    private function schemaValidate(\DOMDocument $document, string $xsdPath): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $valid = $document->schemaValidate($xsdPath);
            $detail = $this->formatLibxmlErrors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $valid) {
            throw $this->schemaInvalid($detail);
        }
    }

    private function loadXml(string $contents): \DOMDocument
    {
        if (stripos($contents, '<!DOCTYPE') !== false) {
            throw new DocumentException(__('filament-accounting::errors.unsafe_xml'));
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        $detail = $this->formatLibxmlErrors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new DocumentException(
                $detail !== ''
                    ? __('filament-accounting::errors.e_invoice_schema_invalid', ['detail' => $detail])
                    : __('filament-accounting::errors.invalid_xml'),
            );
        }

        return $document;
    }

    private function ublInvoiceSchemaPath(): string
    {
        return dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'e-invoice'
            .DIRECTORY_SEPARATOR.'schema'
            .DIRECTORY_SEPARATOR.'ubl'
            .DIRECTORY_SEPARATOR.'Invoice-Subset.xsd';
    }

    /** @param array<string, mixed> $line */
    private function lineNetMinor(array $line): int
    {
        return (int) ($line['line_net_minor'] ?? $line['net_minor'] ?? 0);
    }

    private function schemaInvalid(string $detail, ?\Throwable $previous = null): DocumentException
    {
        return new DocumentException(
            __('filament-accounting::errors.e_invoice_schema_invalid', [
                'detail' => $detail !== '' ? $detail : 'schema validation failed',
            ]),
            previous: $previous,
        );
    }

    private function businessRule(string $rule, string $detail): DocumentException
    {
        return new DocumentException(__('filament-accounting::errors.e_invoice_business_rule_failed', [
            'detail' => $rule.': '.$detail,
        ]));
    }

    private function formatLibxmlErrors(): string
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $messages[] = trim($error->message).' (line '.$error->line.')';
            if (count($messages) >= 3) {
                break;
            }
        }

        return implode('; ', $messages);
    }
}

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
 * Fail-closed schema + DE-EUR EN 16931 business-rule gate for incoming e-invoices.
 *
 * This is a documented subset, not a full Schematron / XRechnung / ZUGFeRD
 * certification engine.
 */
final class ValidateIncomingEInvoice
{
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

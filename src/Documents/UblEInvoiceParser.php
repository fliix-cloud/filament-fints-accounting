<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\LineMoneyCalculator;

final class UblEInvoiceParser
{
    public function supports(string $contents): bool
    {
        return str_contains($contents, 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
            || preg_match('/<(?:\w+:)?Invoice\b/', $contents) === 1;
    }

    public function parse(string $contents, string $filename): EInvoiceParseResult
    {
        $hash = hash('sha256', $contents);
        if (stripos($contents, '<!DOCTYPE') !== false) {
            return $this->invalid($contents, $hash, __('filament-accounting::errors.unsafe_xml'));
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement?->localName !== 'Invoice') {
            return $this->invalid($contents, $hash, implode('; ', $errors) ?: __('filament-accounting::errors.invalid_xml'));
        }

        $xpath = new \DOMXPath($document);
        $currency = strtoupper($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='DocumentCurrencyCode']") ?: 'EUR');
        $lines = [];
        foreach ($xpath->query("/*[local-name()='Invoice']/*[local-name()='InvoiceLine']") ?: [] as $lineNode) {
            $quantity = $this->value($xpath, ".//*[local-name()='InvoicedQuantity']", $lineNode) ?: '1';
            $unitPrice = $this->value($xpath, ".//*[local-name()='Price']/*[local-name()='PriceAmount']", $lineNode) ?: '0';
            $percent = $this->value($xpath, ".//*[local-name()='ClassifiedTaxCategory']/*[local-name()='Percent']", $lineNode);
            $quantityNode = $xpath->query(".//*[local-name()='InvoicedQuantity']", $lineNode)?->item(0);
            $lineNetMinor = $this->minor($this->value($xpath, "./*[local-name()='LineExtensionAmount']", $lineNode), $currency);
            $lineAllowanceCharges = $this->parseAllowanceCharges($xpath, $lineNode, $currency, 'line');
            if ($lineAllowanceCharges !== []) {
                $this->assertLineAllowanceChargesReconcile(
                    $quantity,
                    $unitPrice,
                    $lineNetMinor,
                    $lineAllowanceCharges,
                    $currency,
                    $lineNode,
                    $xpath,
                );
            }

            $line = [
                'position' => $this->value($xpath, "./*[local-name()='ID']", $lineNode),
                'description' => $this->value($xpath, ".//*[local-name()='Item']/*[local-name()='Name']", $lineNode)
                    ?: $this->value($xpath, ".//*[local-name()='Item']/*[local-name()='Description']", $lineNode),
                'quantity' => $quantity,
                'unit' => $quantityNode instanceof \DOMElement ? $quantityNode->getAttribute('unitCode') : null,
                'unit_price' => $unitPrice,
                'tax_rate_bp' => $this->percentToBasisPoints($percent),
                'tax_category' => $this->value($xpath, ".//*[local-name()='ClassifiedTaxCategory']/*[local-name()='ID']", $lineNode),
                'line_net_minor' => $lineNetMinor,
            ];
            if ($lineAllowanceCharges !== []) {
                $line['allowance_charges'] = $lineAllowanceCharges;
            }
            $lines[] = $line;
        }

        $documentAllowanceCharges = $this->parseAllowanceCharges(
            $xpath,
            $document->documentElement,
            $currency,
            'document',
            directChildrenOnly: true,
        );
        $this->assertDocumentAllowanceChargesSupported($xpath, $currency, $lines, $documentAllowanceCharges);

        $number = $this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='ID']");
        $issueDate = $this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='IssueDate']") ?: null;
        $invoiceTypeCode = $this->nullable($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='InvoiceTypeCode']"));
        $customizationId = $this->nullable($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='CustomizationID']"));
        $profileId = $this->nullable($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='ProfileID']"));
        $buyerReference = $this->nullable($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='BuyerReference']"));
        $supplier = $xpath->query("/*[local-name()='Invoice']/*[local-name()='AccountingSupplierParty']")?->item(0);
        $seller = $this->parseParty($xpath, $supplier);
        $customer = $xpath->query("/*[local-name()='Invoice']/*[local-name()='AccountingCustomerParty']")?->item(0);
        $buyer = $this->parseParty($xpath, $customer);
        $net = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxExclusiveAmount']"), $currency);
        if ($net === 0) {
            $net = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='LineExtensionAmount']"), $currency);
        }
        $gross = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxInclusiveAmount']"), $currency);
        $tax = $this->minor($this->value($xpath, "//*[local-name()='TaxTotal']/*[local-name()='TaxAmount']"), $currency);
        $validationErrors = [];
        if ($number === '' || $issueDate === null || $lines === []) {
            $validationErrors[] = __('filament-accounting::errors.invalid_e_invoice');
        }

        $meta = ['filename' => $filename];
        if ($documentAllowanceCharges !== []) {
            $meta['document_allowance_charges'] = $documentAllowanceCharges;
        }
        $representative = $this->parseTaxRepresentative($xpath);

        return new EInvoiceParseResult(
            formatKey: 'ubl',
            documentNumber: $number,
            issueDate: $issueDate,
            currency: $currency,
            grossMinor: $gross,
            netMinor: $net,
            taxMinor: $tax,
            sellerName: $seller['name'],
            sellerVatId: $seller['vat_id'],
            lines: $lines,
            originalXml: $contents,
            sha256: $hash,
            valid: $validationErrors === [],
            errors: $validationErrors,
            meta: $meta,
            sellerAddressLine1: $seller['address_line1'],
            sellerAddressLine2: $seller['address_line2'],
            sellerPostalCode: $seller['postal_code'],
            sellerCity: $seller['city'],
            sellerCountryCode: $seller['country_code'],
            sellerEmail: $seller['email'],
            invoiceTypeCode: $invoiceTypeCode,
            customizationId: $customizationId,
            profileId: $profileId,
            buyerName: $buyer['name'],
            buyerAddressLine1: $buyer['address_line1'],
            buyerPostalCode: $buyer['postal_code'],
            buyerCity: $buyer['city'],
            buyerCountryCode: $buyer['country_code'],
            vatBreakdown: $this->parseVatBreakdown($xpath, $currency),
            sellerElectronicAddress: $seller['electronic_address'],
            sellerElectronicAddressScheme: $seller['electronic_address_scheme'],
            buyerElectronicAddress: $buyer['electronic_address'],
            buyerElectronicAddressScheme: $buyer['electronic_address_scheme'],
            paymentMeans: $this->parsePaymentMeans($xpath),
            buyerReference: $buyerReference,
            sellerContactName: $seller['contact_name'],
            sellerContactPhone: $seller['contact_phone'],
            sellerContactEmail: $seller['contact_email'] ?? $seller['email'],
            sellerTaxRepresentativeName: $representative['name'],
            sellerTaxRepresentativeVatId: $representative['vat_id'],
            sellerTaxRepresentativeCountryCode: $representative['country_code'],
        );
    }

    /**
     * @return list<array{charge_indicator: bool, amount_minor: int, reason: ?string, reason_code: ?string, percent: ?string}>
     */
    private function parseAllowanceCharges(
        \DOMXPath $xpath,
        \DOMNode $context,
        string $currency,
        string $scope,
        bool $directChildrenOnly = false,
    ): array {
        $expression = $directChildrenOnly
            ? "./*[local-name()='AllowanceCharge']"
            : "./*[local-name()='AllowanceCharge']";
        $nodes = $xpath->query($expression, $context) ?: [];
        $items = [];
        foreach ($nodes as $node) {
            // Skip nested AllowanceCharge under TaxTotal / other aggregates when
            // scanning a line or document context with only direct children.
            if ($directChildrenOnly && $node->parentNode !== $context) {
                continue;
            }

            $indicatorRaw = strtolower($this->value($xpath, "./*[local-name()='ChargeIndicator']", $node));
            if ($indicatorRaw !== 'true' && $indicatorRaw !== 'false') {
                throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                    'detail' => 'ChargeIndicator missing or invalid ('.$scope.')',
                ]));
            }
            $amountRaw = $this->value($xpath, "./*[local-name()='Amount']", $node);
            if ($amountRaw === '') {
                throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                    'detail' => 'Amount missing ('.$scope.')',
                ]));
            }

            // BaseAmount / unknown structures we cannot map into a single line net
            // are fail-closed rather than silently dropped.
            if ($this->value($xpath, "./*[local-name()='BaseAmount']", $node) !== '') {
                throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                    'detail' => 'BaseAmount is not supported ('.$scope.')',
                ]));
            }

            $percent = $this->value($xpath, "./*[local-name()='MultiplierFactorNumeric']", $node);
            $items[] = [
                'charge_indicator' => $indicatorRaw === 'true',
                'amount_minor' => $this->minor($amountRaw, $currency),
                'reason' => ($reason = $this->value($xpath, "./*[local-name()='AllowanceChargeReason']", $node)) !== '' ? $reason : null,
                'reason_code' => ($code = $this->value($xpath, "./*[local-name()='AllowanceChargeReasonCode']", $node)) !== '' ? $code : null,
                'percent' => $percent !== '' ? $percent : null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{charge_indicator: bool, amount_minor: int, reason: ?string, reason_code: ?string, percent: ?string}>  $allowanceCharges
     */
    private function assertLineAllowanceChargesReconcile(
        string $quantity,
        string $unitPrice,
        int $lineNetMinor,
        array $allowanceCharges,
        string $currency,
        \DOMNode $lineNode,
        \DOMXPath $xpath,
    ): void {
        $baseQuantity = $this->value($xpath, ".//*[local-name()='Price']/*[local-name()='BaseQuantity']", $lineNode);
        if ($baseQuantity !== '' && $baseQuantity !== '1' && $baseQuantity !== '1.00') {
            throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                'detail' => 'Price BaseQuantity other than 1 is not supported',
            ]));
        }

        $unitPriceMinor = $this->minor($unitPrice, $currency);
        $grossLine = LineMoneyCalculator::netMinor($quantity, $unitPriceMinor);
        $allowance = 0;
        $charge = 0;
        foreach ($allowanceCharges as $item) {
            if ($item['charge_indicator']) {
                $charge += $item['amount_minor'];
            } else {
                $allowance += $item['amount_minor'];
            }
        }
        $expected = $grossLine - $allowance + $charge;
        if ($expected !== $lineNetMinor) {
            throw new DocumentException(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
                'detail' => "line expected {$expected}, got {$lineNetMinor}",
            ]));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{charge_indicator: bool, amount_minor: int, reason: ?string, reason_code: ?string, percent: ?string}>  $documentAllowanceCharges
     */
    private function assertDocumentAllowanceChargesSupported(
        \DOMXPath $xpath,
        string $currency,
        array $lines,
        array $documentAllowanceCharges,
    ): void {
        if ($documentAllowanceCharges === []) {
            return;
        }

        $lineExtension = $this->minor(
            $this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='LineExtensionAmount']"),
            $currency,
        );
        $taxExclusive = $this->minor(
            $this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxExclusiveAmount']"),
            $currency,
        );
        $taxInclusive = $this->minor(
            $this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxInclusiveAmount']"),
            $currency,
        );
        $allowanceTotalRaw = $this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='AllowanceTotalAmount']");
        $chargeTotalRaw = $this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='ChargeTotalAmount']");
        $allowanceTotal = $allowanceTotalRaw === '' ? null : $this->minor($allowanceTotalRaw, $currency);
        $chargeTotal = $chargeTotalRaw === '' ? null : $this->minor($chargeTotalRaw, $currency);
        $tax = $this->minor($this->value($xpath, "//*[local-name()='TaxTotal']/*[local-name()='TaxAmount']"), $currency);

        $sumAllowance = 0;
        $sumCharge = 0;
        foreach ($documentAllowanceCharges as $item) {
            if ($item['charge_indicator']) {
                $sumCharge += $item['amount_minor'];
            } else {
                $sumAllowance += $item['amount_minor'];
            }
        }

        if ($allowanceTotal !== null && $allowanceTotal !== $sumAllowance) {
            throw new DocumentException(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
                'detail' => 'document AllowanceTotalAmount does not match AllowanceCharge sums',
            ]));
        }
        if ($chargeTotal !== null && $chargeTotal !== $sumCharge) {
            throw new DocumentException(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
                'detail' => 'document ChargeTotalAmount does not match AllowanceCharge sums',
            ]));
        }

        $effectiveAllowance = $allowanceTotal ?? $sumAllowance;
        $effectiveCharge = $chargeTotal ?? $sumCharge;
        if ($lineExtension - $effectiveAllowance + $effectiveCharge !== $taxExclusive) {
            throw new DocumentException(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
                'detail' => 'LegalMonetaryTotal does not reconcile LineExtension/Allowance/Charge/TaxExclusive',
            ]));
        }
        if ($taxInclusive !== 0 && $taxExclusive + $tax !== $taxInclusive) {
            throw new DocumentException(__('filament-accounting::errors.allowance_charge_totals_mismatch', [
                'detail' => 'TaxExclusive + TaxAmount does not equal TaxInclusiveAmount',
            ]));
        }

        $sumLineNets = array_sum(array_map(
            static fn (array $line): int => (int) ($line['line_net_minor'] ?? 0),
            $lines,
        ));

        // Document-level allowances that change the invoice net below the sum of
        // line nets would require separate posting lines this package cannot map.
        // Only the zero-net-effect / already-baked subset is accepted.
        if ($taxExclusive !== $sumLineNets) {
            throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                'detail' => 'document-level AllowanceCharge changes net below sum of line nets and cannot be posted',
            ]));
        }
    }

    private function value(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')', $context));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @return array{name: ?string, vat_id: ?string, address_line1: ?string, address_line2: ?string, postal_code: ?string, city: ?string, country_code: ?string, email: ?string, electronic_address: ?string, electronic_address_scheme: ?string, contact_name: ?string, contact_phone: ?string, contact_email: ?string}
     */
    private function parseParty(\DOMXPath $xpath, ?\DOMNode $party): array
    {
        if (! $party instanceof \DOMNode) {
            return [
                'name' => null,
                'vat_id' => null,
                'address_line1' => null,
                'address_line2' => null,
                'postal_code' => null,
                'city' => null,
                'country_code' => null,
                'email' => null,
                'electronic_address' => null,
                'electronic_address_scheme' => null,
                'contact_name' => null,
                'contact_phone' => null,
                'contact_email' => null,
            ];
        }

        $endpoint = $xpath->query(".//*[local-name()='EndpointID']", $party)?->item(0);
        $contactEmail = $this->nullable($this->value($xpath, ".//*[local-name()='Contact']/*[local-name()='ElectronicMail']", $party));

        return [
            'name' => $this->nullable(
                $this->value($xpath, ".//*[local-name()='PartyName']/*[local-name()='Name']", $party)
                    ?: $this->value($xpath, ".//*[local-name()='PartyLegalEntity']/*[local-name()='RegistrationName']", $party),
            ),
            'vat_id' => $this->partyVatIdentifier($xpath, $party),
            'address_line1' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']/*[local-name()='StreetName']", $party)),
            'address_line2' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']/*[local-name()='AdditionalStreetName']", $party)),
            'postal_code' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']/*[local-name()='PostalZone']", $party)),
            'city' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']/*[local-name()='CityName']", $party)),
            'country_code' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']//*[local-name()='IdentificationCode']", $party)),
            'email' => $contactEmail,
            'electronic_address' => $endpoint instanceof \DOMNode ? $this->nullable(trim($endpoint->textContent)) : null,
            'electronic_address_scheme' => $endpoint instanceof \DOMElement
                ? $this->nullable($endpoint->getAttribute('schemeID'))
                : null,
            'contact_name' => $this->nullable($this->value($xpath, ".//*[local-name()='Contact']/*[local-name()='Name']", $party)),
            'contact_phone' => $this->nullable($this->value($xpath, ".//*[local-name()='Contact']/*[local-name()='Telephone']", $party)),
            'contact_email' => $contactEmail,
        ];
    }

    private function partyVatIdentifier(\DOMXPath $xpath, \DOMNode $party): ?string
    {
        foreach ($xpath->query(".//*[local-name()='PartyTaxScheme']", $party) ?: [] as $scheme) {
            $identifier = $this->nullable($this->value($xpath, "./*[local-name()='CompanyID']", $scheme));
            if ($identifier === null) {
                continue;
            }
            $taxScheme = strtoupper($this->value($xpath, "./*[local-name()='TaxScheme']/*[local-name()='ID']", $scheme));
            if ($taxScheme === '' || $taxScheme === 'VAT') {
                return $identifier;
            }
        }

        return null;
    }

    /** @return array{name: ?string, vat_id: ?string, country_code: ?string} */
    private function parseTaxRepresentative(\DOMXPath $xpath): array
    {
        $party = $xpath->query("/*[local-name()='Invoice']/*[local-name()='TaxRepresentativeParty']")?->item(0);
        if (! $party instanceof \DOMNode) {
            return ['name' => null, 'vat_id' => null, 'country_code' => null];
        }

        return [
            'name' => $this->nullable($this->value($xpath, ".//*[local-name()='PartyName']/*[local-name()='Name']", $party)),
            'vat_id' => $this->partyVatIdentifier($xpath, $party),
            'country_code' => $this->nullable($this->value($xpath, ".//*[local-name()='PostalAddress']//*[local-name()='IdentificationCode']", $party)),
        ];
    }

    /**
     * @return list<array{type_code: string, payee_iban: ?string, debtor_iban: ?string}>
     */
    private function parsePaymentMeans(\DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query("/*[local-name()='Invoice']/*[local-name()='PaymentMeans']") ?: [] as $node) {
            $items[] = [
                'type_code' => $this->value($xpath, "./*[local-name()='PaymentMeansCode']", $node),
                'payee_iban' => $this->nullable($this->value($xpath, "./*[local-name()='PayeeFinancialAccount']/*[local-name()='ID']", $node)),
                'debtor_iban' => $this->nullable(
                    $this->value($xpath, "./*[local-name()='PaymentMandate']/*[local-name()='PayerFinancialAccount']/*[local-name()='ID']", $node)
                        ?: $this->value($xpath, "./*[local-name()='PayerFinancialAccount']/*[local-name()='ID']", $node),
                ),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{category: string, rate_bp: int|null, taxable_minor: int, tax_minor: int}>
     */
    private function parseVatBreakdown(\DOMXPath $xpath, string $currency): array
    {
        $groups = [];
        foreach ($xpath->query("/*[local-name()='Invoice']/*[local-name()='TaxTotal']/*[local-name()='TaxSubtotal']") ?: [] as $node) {
            $groups[] = [
                'category' => $this->value($xpath, "./*[local-name()='TaxCategory']/*[local-name()='ID']", $node),
                'rate_bp' => $this->percentToBasisPoints($this->value($xpath, "./*[local-name()='TaxCategory']/*[local-name()='Percent']", $node)),
                'taxable_minor' => $this->minor($this->value($xpath, "./*[local-name()='TaxableAmount']", $node), $currency),
                'tax_minor' => $this->minor($this->value($xpath, "./*[local-name()='TaxAmount']", $node), $currency),
            ];
        }

        return $groups;
    }

    private function percentToBasisPoints(string $percent): ?int
    {
        $percent = trim(str_replace(',', '.', $percent));
        if ($percent === '' || ! is_numeric($percent)) {
            return null;
        }

        try {
            return ExactMoney::ofString($percent, 'EUR')->minorAmount;
        } catch (InvalidMoneyException) {
            return null;
        }
    }

    private function minor(string $value, string $currency): int
    {
        return $value === '' ? 0 : ExactMoney::ofString($value, $currency)->minorAmount;
    }

    private function invalid(string $contents, string $hash, string $error): EInvoiceParseResult
    {
        return new EInvoiceParseResult('ubl', '', null, 'EUR', 0, 0, 0, null, null, [], $contents, $hash, false, [$error]);
    }
}

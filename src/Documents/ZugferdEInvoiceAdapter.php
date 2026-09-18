<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Banking\FinTs\Support\Money;
use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\RichText;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdProfiles;

final class ZugferdEInvoiceAdapter implements EInvoiceAdapter
{
    public function formatKey(): string
    {
        return 'zugferd';
    }

    public function supports(string $mimeType, string $contents): bool
    {
        if (! str_contains($mimeType, 'xml') && ! str_contains($contents, 'CrossIndustryInvoice')) {
            return false;
        }

        return str_contains($contents, 'CrossIndustryInvoice') || str_contains($contents, 'rsm:ExchangedDocument');
    }

    public function parse(string $contents, string $filename): EInvoiceParseResult
    {
        $hash = hash('sha256', $contents);

        try {
            $reader = ZugferdDocumentReader::readAndGuessFromContent($contents);
        } catch (\Throwable $e) {
            return new EInvoiceParseResult(
                formatKey: $this->formatKey(),
                documentNumber: '',
                issueDate: null,
                currency: 'EUR',
                grossMinor: 0,
                netMinor: 0,
                taxMinor: 0,
                sellerName: null,
                sellerVatId: null,
                lines: [],
                originalXml: $contents,
                sha256: $hash,
                valid: false,
                errors: [$e->getMessage()],
            );
        }

        $documentNo = null;
        $documentTypeCode = null;
        $documentDate = null;
        $invoiceCurrency = null;
        $taxCurrency = null;
        $documentName = null;
        $documentLanguage = null;
        $period = null;
        $reader->getDocumentInformation($documentNo, $documentTypeCode, $documentDate, $invoiceCurrency, $taxCurrency, $documentName, $documentLanguage, $period);

        $sellerName = null;
        $sellerIds = null;
        $sellerDescription = null;
        $reader->getDocumentSeller($sellerName, $sellerIds, $sellerDescription);
        $lineOne = null;
        $lineTwo = null;
        $lineThree = null;
        $sellerPostcode = null;
        $sellerCity = null;
        $sellerCountry = null;
        $subDivision = null;
        $reader->getDocumentSellerAddress($lineOne, $lineTwo, $lineThree, $sellerPostcode, $sellerCity, $sellerCountry, $subDivision);

        $taxReg = null;
        $reader->getDocumentSellerTaxRegistration($taxReg);
        $vatId = is_array($taxReg) ? ($taxReg['VA'] ?? $taxReg['FC'] ?? null) : null;

        $grand = null;
        $lineTotal = null;
        $taxBasis = null;
        $taxTotal = null;
        $allowance = null;
        $charge = null;
        $prepaid = null;
        $rounding = null;
        $due = null;
        $reader->getDocumentSummation($grand, $due, $lineTotal, $charge, $allowance, $taxBasis, $taxTotal, $rounding, $prepaid);

        $currency = strtoupper((string) ($invoiceCurrency ?: 'EUR'));
        $gross = $this->toMinor($grand, $currency);
        $net = $this->toMinor($taxBasis ?? $lineTotal, $currency);
        $tax = $this->toMinor($taxTotal, $currency);

        $lines = [];
        $reader->firstDocumentPosition();
        do {
            $lineId = null;
            $lineStatusCode = null;
            $lineStatusReason = null;
            $reader->getDocumentPositionGenerals($lineId, $lineStatusCode, $lineStatusReason);
            $quantity = null;
            $unitCode = null;
            $chargeFreeQty = null;
            $chargeFreeUnit = null;
            $packageQty = null;
            $packageUnit = null;
            $reader->getDocumentPositionQuantity($quantity, $unitCode, $chargeFreeQty, $chargeFreeUnit, $packageQty, $packageUnit);
            $name = null;
            $description = null;
            $sellerNumber = null;
            $buyerNumber = null;
            $globalIdType = null;
            $globalId = null;
            $reader->getDocumentPositionProductDetails($name, $description, $sellerNumber, $buyerNumber, $globalIdType, $globalId);
            $netLine = null;
            $basisQty = null;
            $basisUnit = null;
            $reader->getDocumentPositionNetPrice($netLine, $basisQty, $basisUnit);
            $taxCategory = null;
            $taxType = null;
            $taxRate = null;
            $calculatedTax = null;
            $taxReason = null;
            $taxReasonCode = null;
            $reader->getDocumentPositionTax($taxCategory, $taxType, $taxRate, $calculatedTax, $taxReason, $taxReasonCode);
            // The line's own net, tax and grand totals (after any line-level
            // allowance or charge) keep the import faithful to the source.
            $lineTotal = null;
            $chargeTotal = null;
            $allowanceTotal = null;
            $lineTax = null;
            $lineGrand = null;
            $allowanceCharge = null;
            $reader->getDocumentPositionLineSummationExt(
                $lineTotal, $chargeTotal, $allowanceTotal, $lineTax, $lineGrand, $allowanceCharge,
            );
            $lineEntry = [
                'position' => $lineId,
                'description' => $name ?: $description,
                'quantity' => $quantity !== null ? (string) $quantity : '1',
                'unit' => $unitCode,
                'unit_price' => $netLine !== null ? (string) $netLine : '0',
                'tax_rate_bp' => $taxRate !== null ? ExactMoney::ofString(Money::fromFloat($taxRate), $currency)->minorAmount : null,
                'tax_category' => $taxCategory,
                'tax_reason' => $taxReason,
                'net_minor' => $this->toMinor($lineTotal ?? $netLine, $currency),
                'tax_minor' => $this->toMinor($lineTax ?? $calculatedTax, $currency),
            ];
            $lineAllowanceMinor = $this->toMinor($allowanceTotal, $currency);
            $lineChargeMinor = $this->toMinor($chargeTotal, $currency);
            if ($lineAllowanceMinor !== 0 || $lineChargeMinor !== 0) {
                $lineEntry['allowance_charges'] = array_values(array_filter([
                    $lineAllowanceMinor !== 0 ? [
                        'charge_indicator' => false,
                        'amount_minor' => $lineAllowanceMinor,
                        'reason' => null,
                        'reason_code' => null,
                        'percent' => null,
                    ] : null,
                    $lineChargeMinor !== 0 ? [
                        'charge_indicator' => true,
                        'amount_minor' => $lineChargeMinor,
                        'reason' => null,
                        'reason_code' => null,
                        'percent' => null,
                    ] : null,
                ]));
            }
            $lines[] = $lineEntry;
        } while ($reader->nextDocumentPosition());

        $documentAllowanceMinor = $this->toMinor($allowance, $currency);
        $documentChargeMinor = $this->toMinor($charge, $currency);
        $documentAllowanceCharges = [];
        if ($documentAllowanceMinor !== 0) {
            $documentAllowanceCharges[] = [
                'charge_indicator' => false,
                'amount_minor' => $documentAllowanceMinor,
                'reason' => null,
                'reason_code' => null,
                'percent' => null,
            ];
        }
        if ($documentChargeMinor !== 0) {
            $documentAllowanceCharges[] = [
                'charge_indicator' => true,
                'amount_minor' => $documentChargeMinor,
                'reason' => null,
                'reason_code' => null,
                'percent' => null,
            ];
        }
        if ($documentAllowanceCharges !== []) {
            $sumLineNets = array_sum(array_map(
                static fn (array $line): int => (int) $line['net_minor'],
                $lines,
            ));
            // Non-zero document-level allowance/charge that moves the invoice net
            // away from the sum of line nets cannot be mapped to posting lines yet.
            if ($net !== $sumLineNets) {
                throw new DocumentException(__('filament-accounting::errors.unsupported_allowance_charge', [
                    'detail' => 'document-level AllowanceCharge changes net below sum of line nets and cannot be posted',
                ]));
            }
        }

        $meta = [
            'filename' => $filename,
            'type_code' => $documentTypeCode,
            'seller_city' => $sellerCity,
            'seller_country' => $sellerCountry,
        ];
        if ($documentAllowanceCharges !== []) {
            $meta['document_allowance_charges'] = $documentAllowanceCharges;
        }

        return new EInvoiceParseResult(
            formatKey: $this->formatKey(),
            documentNumber: (string) $documentNo,
            issueDate: $documentDate?->format('Y-m-d'),
            currency: $currency,
            grossMinor: $gross,
            netMinor: $net,
            taxMinor: $tax,
            sellerName: $sellerName,
            sellerVatId: is_scalar($vatId) ? (string) $vatId : null,
            lines: $lines,
            originalXml: $contents,
            sha256: $hash,
            valid: true,
            errors: [],
            meta: $meta,
            sellerAddressLine1: $lineOne,
            sellerAddressLine2: implode(' ', array_filter([$lineTwo, $lineThree])),
            sellerPostalCode: $sellerPostcode,
            sellerCity: $sellerCity,
            sellerCountryCode: $sellerCountry,
        );
    }

    public function generate(array $snapshot): string
    {
        $builder = ZugferdDocumentBuilder::CreateNew(ZugferdProfiles::PROFILE_EN16931);
        $number = (string) ($snapshot['number'] ?? 'DRAFT');
        $issueDate = new \DateTimeImmutable((string) ($snapshot['issue_date'] ?? 'now'));
        $currency = (string) ($snapshot['currency'] ?? 'EUR');

        $seller = (array) ($snapshot['seller'] ?? []);
        $buyer = (array) ($snapshot['buyer'] ?? []);
        $builder->setDocumentInformation($number, filled($snapshot['preceding_invoice_number'] ?? null) ? '384' : '380', \DateTime::createFromImmutable($issueDate), $currency);
        if (filled($snapshot['preceding_invoice_number'] ?? null)) {
            $builder->setDocumentInvoiceReferencedDocument(
                (string) $snapshot['preceding_invoice_number'],
                issueDate: new \DateTimeImmutable((string) $snapshot['preceding_invoice_date']),
            );
            $builder->addDocumentNote((string) ($snapshot['correction_reason'] ?? ''));
            $builder->addDocumentNote(__('filament-accounting::fields.invoice_version').': '.($snapshot['invoice_version'] ?? ''));
        }
        $builder->setDocumentSeller((string) ($seller['legal_name'] ?? $snapshot['seller_name'] ?? 'Seller'));
        $builder->setDocumentSellerAddress(
            $seller['address_line1'] ?? null,
            $seller['address_line2'] ?? null,
            null,
            $seller['postal_code'] ?? null,
            $seller['city'] ?? null,
            $seller['country_code'] ?? null,
            $seller['region'] ?? null,
        );
        if (filled($seller['vat_id'] ?? null)) {
            $builder->addDocumentSellerTaxRegistration('VA', (string) $seller['vat_id']);
        }
        if (filled($seller['tax_number'] ?? null)) {
            $builder->addDocumentSellerTaxNumber((string) $seller['tax_number']);
        }
        $builder->setDocumentSellerContact(null, null, $seller['phone'] ?? null, null, $seller['email'] ?? null);

        $buyerAddress = isset($buyer['addresses'][0]) && is_array($buyer['addresses'][0]) ? $buyer['addresses'][0] : [];
        $builder->setDocumentBuyer((string) ($buyer['legal_name'] ?? $snapshot['buyer_name'] ?? 'Buyer'));
        $builder->setDocumentBuyerAddress(
            $buyerAddress['line1'] ?? null,
            $buyerAddress['line2'] ?? null,
            null,
            $buyerAddress['postal_code'] ?? null,
            $buyerAddress['city'] ?? null,
            $buyerAddress['country_code'] ?? $buyer['country_code'] ?? null,
            $buyerAddress['region'] ?? null,
        );
        foreach ((array) ($buyer['vat_ids'] ?? []) as $taxId) {
            if (is_array($taxId) && ($taxId['type'] ?? null) === 'vat') {
                $builder->addDocumentBuyerTaxRegistration('VA', (string) ($taxId['number'] ?? ''));
            }
        }

        $payment = (array) ($snapshot['payment'] ?? []);
        if (($payment['method'] ?? null) === 'direct_debit') {
            if (blank($payment['debtor_iban'] ?? null) || blank($payment['mandate_reference'] ?? null) || blank($payment['creditor_identifier'] ?? null)) {
                throw new DocumentException(__('filament-accounting::invoice.mandate_required'));
            }
            $builder->addDocumentPaymentMeanToDirectDebit((string) $payment['debtor_iban'], (string) $payment['creditor_identifier']);
        } elseif (filled($seller['invoice_iban'] ?? null)) {
            $builder->addDocumentPaymentMeanToCreditTransfer(
                (string) $seller['invoice_iban'],
                (string) ($seller['legal_name'] ?? ''),
                null,
                filled($seller['invoice_bic'] ?? null) ? (string) $seller['invoice_bic'] : null,
                $number,
            );
        }
        if (filled($snapshot['due_date'] ?? null) || filled($payment['mandate_reference'] ?? null)) {
            $builder->addDocumentPaymentTerm(null,
                filled($snapshot['due_date'] ?? null) ? new \DateTimeImmutable((string) $snapshot['due_date']) : null,
                $payment['mandate_reference'] ?? null,
            );
        }

        $position = 1;
        $taxGroups = [];
        foreach ($snapshot['lines'] ?? [] as $line) {
            $builder->addNewPosition((string) $position);
            $builder->setDocumentPositionProductDetails(RichText::plainText((string) ($line['description'] ?? 'Item')));
            $quantity = (float) ($line['quantity'] ?? 1);
            $builder->setDocumentPositionQuantity($quantity, (string) ($line['unit'] ?? 'C62'));
            $unitPrice = isset($line['unit_price_minor'])
                ? ExactMoney::ofMinor((int) $line['unit_price_minor'], $currency)->decimalString()
                : (string) ($line['unit_price'] ?? '0');
            $builder->setDocumentPositionNetPrice((float) $unitPrice);
            $rate = ((int) ($line['tax_rate_bp'] ?? 0)) / 100;
            $category = $this->taxCategory((string) ($line['tax_category'] ?? 'standard'), $rate);
            $lineTax = ExactMoney::ofMinor((int) ($line['tax_minor'] ?? 0), $currency)->decimalString();
            $lineNet = ExactMoney::ofMinor((int) ($line['net_minor'] ?? 0), $currency)->decimalString();
            $builder->addDocumentPositionTax(
                $category,
                'VAT',
                $rate,
                (float) $lineTax,
                filled($line['tax_reason'] ?? null) ? (string) $line['tax_reason'] : null,
            );
            $builder->setDocumentPositionLineSummation((float) $lineNet);
            $key = $category.'|'.$rate.'|'.(string) ($line['tax_reason'] ?? '');
            $taxGroups[$key] ??= ['category' => $category, 'rate' => $rate, 'net_minor' => 0, 'tax_minor' => 0, 'reason' => $line['tax_reason'] ?? null];
            $taxGroups[$key]['net_minor'] += (int) ($line['net_minor'] ?? 0);
            $taxGroups[$key]['tax_minor'] += (int) ($line['tax_minor'] ?? 0);
            $position++;
        }

        foreach ($taxGroups as $taxGroup) {
            $builder->addDocumentTax(
                (string) $taxGroup['category'],
                'VAT',
                (float) ExactMoney::ofMinor((int) $taxGroup['net_minor'], $currency)->decimalString(),
                (float) ExactMoney::ofMinor((int) $taxGroup['tax_minor'], $currency)->decimalString(),
                (float) $taxGroup['rate'],
                filled($taxGroup['reason']) ? (string) $taxGroup['reason'] : null,
            );
        }

        $gross = ExactMoney::ofMinor((int) ($snapshot['gross_minor'] ?? 0), $currency)->decimalString();
        $net = ExactMoney::ofMinor((int) ($snapshot['net_minor'] ?? 0), $currency)->decimalString();
        $tax = ExactMoney::ofMinor((int) ($snapshot['tax_minor'] ?? 0), $currency)->decimalString();
        $builder->setDocumentSummation((float) $gross, (float) $gross, (float) $net, 0.0, 0.0, (float) $net, (float) $tax);

        return $builder->getContent();
    }

    private function taxCategory(string $category, float $rate): string
    {
        return match ($category) {
            'exempt', 'non_taxable' => 'E',
            'reverse_charge' => 'AE',
            'intra_community_acquisition' => 'K',
            'zero' => 'Z',
            default => $rate === 0.0 ? 'Z' : 'S',
        };
    }

    private function toMinor(mixed $amount, string $currency): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return ExactMoney::ofString((string) $amount, $currency)->minorAmount;
    }
}

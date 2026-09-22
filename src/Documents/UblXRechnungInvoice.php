<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\RichText;

/**
 * XRechnung 3.0 UBL Invoice for the documented DE-EUR issuing subset.
 *
 * The XML is the structured original. It is not embedded as Factur-X.
 */
final class UblXRechnungInvoice
{
    private const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    private const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** @param  array<string, mixed>  $snapshot */
    public function generate(array $snapshot): string
    {
        $seller = is_array($snapshot['seller'] ?? null) ? $snapshot['seller'] : [];
        $buyer = is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [];
        $payment = is_array($snapshot['payment'] ?? null) ? $snapshot['payment'] : [];
        $currency = strtoupper((string) ($snapshot['currency'] ?? 'EUR'));
        $buyerAddress = $this->buyerAddress($buyer);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $invoice = $document->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $invoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $document->appendChild($invoice);

        $this->text($document, $invoice, 'cbc', 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->text($document, $invoice, 'cbc', 'ProfileID', self::PROFILE_ID);
        $this->text($document, $invoice, 'cbc', 'ID', (string) ($snapshot['number'] ?? ''));
        $this->text($document, $invoice, 'cbc', 'IssueDate', (string) ($snapshot['issue_date'] ?? ''));
        $this->text($document, $invoice, 'cbc', 'InvoiceTypeCode', filled($snapshot['preceding_invoice_number'] ?? null) ? '384' : '380');
        $this->text($document, $invoice, 'cbc', 'DocumentCurrencyCode', $currency);
        $this->text(
            $document,
            $invoice,
            'cbc',
            'BuyerReference',
            (string) ($snapshot['buyer_reference'] ?? $buyer['buyer_reference'] ?? $buyer['external_reference'] ?? ''),
        );

        $this->appendSeller($document, $invoice, $seller, $payment);
        $this->appendBuyer($document, $invoice, $buyer, $buyerAddress);
        $this->appendTaxRepresentative($document, $invoice, $seller);
        $this->appendPayment($document, $invoice, $seller, $payment);
        $this->appendTotals($document, $invoice, $snapshot, $currency);

        return (string) $document->saveXML();
    }

    /** @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $payment
     */
    private function appendSeller(\DOMDocument $document, \DOMElement $invoice, array $seller, array $payment): void
    {
        $party = $this->party($document, $invoice, 'AccountingSupplierParty');
        $scheme = strtoupper(trim((string) ($seller['electronic_address_scheme'] ?? 'EM')));
        $endpoint = (string) ($seller['electronic_address'] ?? $seller['email'] ?? '');
        $this->endpoint($document, $party, $scheme, $endpoint);
        $this->named($document, $party, (string) ($seller['legal_name'] ?? ''));
        $this->address(
            $document,
            $party,
            (string) ($seller['address_line1'] ?? ''),
            (string) ($seller['city'] ?? ''),
            (string) ($seller['postal_code'] ?? ''),
            (string) ($seller['country_code'] ?? ''),
        );
        if (($payment['method'] ?? null) === 'direct_debit' && filled($payment['creditor_identifier'] ?? null)) {
            $identification = $this->element($document, $party, 'cac', 'PartyIdentification');
            $id = $this->text($document, $identification, 'cbc', 'ID', (string) $payment['creditor_identifier']);
            $id->setAttribute('schemeID', 'SEPA');
        }
        if (filled($seller['vat_id'] ?? null)) {
            $this->vat($document, $party, (string) $seller['vat_id']);
        }
        $contact = $this->element($document, $party, 'cac', 'Contact');
        $this->text($document, $contact, 'cbc', 'Name', (string) ($seller['invoice_contact_name'] ?? $seller['contact_name'] ?? ''));
        $this->text($document, $contact, 'cbc', 'Telephone', (string) ($seller['phone'] ?? ''));
        $this->text($document, $contact, 'cbc', 'ElectronicMail', (string) ($seller['email'] ?? ''));
    }

    /** @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $address
     */
    private function appendBuyer(\DOMDocument $document, \DOMElement $invoice, array $buyer, array $address): void
    {
        $party = $this->party($document, $invoice, 'AccountingCustomerParty');
        $scheme = strtoupper(trim((string) ($buyer['electronic_address_scheme'] ?? 'EM')));
        $endpoint = (string) ($buyer['electronic_address'] ?? $buyer['invoice_email'] ?? $buyer['email'] ?? '');
        $this->endpoint($document, $party, $scheme, $endpoint);
        $this->named($document, $party, (string) ($buyer['legal_name'] ?? ''));
        $this->address(
            $document,
            $party,
            (string) ($address['line1'] ?? ''),
            (string) ($address['city'] ?? ''),
            (string) ($address['postal_code'] ?? ''),
            (string) ($address['country_code'] ?? $buyer['country_code'] ?? ''),
        );
    }

    /** @param  array<string, mixed>  $seller */
    private function appendTaxRepresentative(\DOMDocument $document, \DOMElement $invoice, array $seller): void
    {
        if (! filled($seller['tax_representative_name'] ?? null) || ! filled($seller['tax_representative_vat_id'] ?? null)) {
            return;
        }
        $party = $this->element($document, $invoice, 'cac', 'TaxRepresentativeParty');
        $this->named($document, $party, (string) $seller['tax_representative_name']);
        $this->address($document, $party, '', '', '', (string) ($seller['tax_representative_country_code'] ?? ''));
        $this->vat($document, $party, (string) $seller['tax_representative_vat_id']);
    }

    /** @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $payment
     */
    private function appendPayment(\DOMDocument $document, \DOMElement $invoice, array $seller, array $payment): void
    {
        $means = $this->element($document, $invoice, 'cac', 'PaymentMeans');
        if (($payment['method'] ?? null) === 'direct_debit') {
            $this->text($document, $means, 'cbc', 'PaymentMeansCode', '59');
            $mandate = $this->element($document, $means, 'cac', 'PaymentMandate');
            $this->text($document, $mandate, 'cbc', 'ID', (string) ($payment['mandate_reference'] ?? ''));
            $account = $this->element($document, $mandate, 'cac', 'PayerFinancialAccount');
            $this->text($document, $account, 'cbc', 'ID', (string) ($payment['debtor_iban'] ?? ''));

            return;
        }
        if (! filled($seller['invoice_iban'] ?? null)) {
            $invoice->removeChild($means);

            return;
        }
        $this->text($document, $means, 'cbc', 'PaymentMeansCode', '58');
        $account = $this->element($document, $means, 'cac', 'PayeeFinancialAccount');
        $this->text($document, $account, 'cbc', 'ID', (string) $seller['invoice_iban']);
    }

    /** @param  array<string, mixed>  $snapshot */
    private function appendTotals(\DOMDocument $document, \DOMElement $invoice, array $snapshot, string $currency): void
    {
        /** @var array<string, array{category: string, rate_bp: int, taxable: int, tax: int}> $groups */
        $groups = [];
        $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        $position = 1;
        $lineNodes = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rateBp = (int) ($line['tax_rate_bp'] ?? 0);
            $category = ZugferdEInvoiceAdapter::untDidTaxCategory((string) ($line['tax_category'] ?? 'standard'), $rateBp);
            $net = (int) ($line['net_minor'] ?? 0);
            $tax = (int) ($line['tax_minor'] ?? 0);
            $key = $category.'|'.$rateBp;
            $groups[$key] ??= ['category' => $category, 'rate_bp' => $rateBp, 'taxable' => 0, 'tax' => 0];
            $groups[$key]['taxable'] += $net;
            $groups[$key]['tax'] += $tax;

            $invoiceLine = $this->element($document, $invoice, 'cac', 'InvoiceLine');
            $this->text($document, $invoiceLine, 'cbc', 'ID', (string) $position);
            $quantity = $this->text($document, $invoiceLine, 'cbc', 'InvoicedQuantity', (string) ($line['quantity'] ?? '1'));
            $quantity->setAttribute('unitCode', (string) ($line['unit'] ?? 'C62'));
            $this->amount($document, $invoiceLine, 'LineExtensionAmount', $net, $currency);
            $item = $this->element($document, $invoiceLine, 'cac', 'Item');
            $this->text($document, $item, 'cbc', 'Name', RichText::plainText((string) ($line['description'] ?? 'Item')) ?: 'Item');
            $classified = $this->element($document, $item, 'cac', 'ClassifiedTaxCategory');
            $this->text($document, $classified, 'cbc', 'ID', $category);
            $this->text($document, $classified, 'cbc', 'Percent', $this->percent($rateBp));
            $taxScheme = $this->element($document, $classified, 'cac', 'TaxScheme');
            $this->text($document, $taxScheme, 'cbc', 'ID', 'VAT');
            $price = $this->element($document, $invoiceLine, 'cac', 'Price');
            $this->amount($document, $price, 'PriceAmount', (int) ($line['unit_price_minor'] ?? 0), $currency);
            $lineNodes[] = $invoiceLine;
            $position++;
        }

        $taxTotal = $this->element($document, $invoice, 'cac', 'TaxTotal');
        $this->amount($document, $taxTotal, 'TaxAmount', (int) ($snapshot['tax_minor'] ?? 0), $currency);
        foreach ($groups as $group) {
            $subtotal = $this->element($document, $taxTotal, 'cac', 'TaxSubtotal');
            $this->amount($document, $subtotal, 'TaxableAmount', $group['taxable'], $currency);
            $this->amount($document, $subtotal, 'TaxAmount', $group['tax'], $currency);
            $taxCategory = $this->element($document, $subtotal, 'cac', 'TaxCategory');
            $this->text($document, $taxCategory, 'cbc', 'ID', $group['category']);
            $this->text($document, $taxCategory, 'cbc', 'Percent', $this->percent($group['rate_bp']));
            $scheme = $this->element($document, $taxCategory, 'cac', 'TaxScheme');
            $this->text($document, $scheme, 'cbc', 'ID', 'VAT');
        }

        $monetary = $this->element($document, $invoice, 'cac', 'LegalMonetaryTotal');
        $net = (int) ($snapshot['net_minor'] ?? 0);
        $this->amount($document, $monetary, 'LineExtensionAmount', $net, $currency);
        $this->amount($document, $monetary, 'TaxExclusiveAmount', $net, $currency);
        $this->amount($document, $monetary, 'TaxInclusiveAmount', (int) ($snapshot['gross_minor'] ?? 0), $currency);

        foreach ($lineNodes as $lineNode) {
            $invoice->appendChild($lineNode);
        }
    }

    private function party(\DOMDocument $document, \DOMElement $invoice, string $name): \DOMElement
    {
        $wrapper = $this->element($document, $invoice, 'cac', $name);

        return $this->element($document, $wrapper, 'cac', 'Party');
    }

    private function endpoint(\DOMDocument $document, \DOMElement $party, string $scheme, string $value): void
    {
        $endpoint = $this->text($document, $party, 'cbc', 'EndpointID', $value);
        $endpoint->setAttribute('schemeID', $scheme);
    }

    private function named(\DOMDocument $document, \DOMElement $party, string $name): void
    {
        $partyName = $this->element($document, $party, 'cac', 'PartyName');
        $this->text($document, $partyName, 'cbc', 'Name', $name);
    }

    private function address(\DOMDocument $document, \DOMElement $party, string $line1, string $city, string $postCode, string $country): void
    {
        $address = $this->element($document, $party, 'cac', 'PostalAddress');
        if ($line1 !== '') {
            $this->text($document, $address, 'cbc', 'StreetName', $line1);
        }
        if ($city !== '') {
            $this->text($document, $address, 'cbc', 'CityName', $city);
        }
        if ($postCode !== '') {
            $this->text($document, $address, 'cbc', 'PostalZone', $postCode);
        }
        $countryNode = $this->element($document, $address, 'cac', 'Country');
        $this->text($document, $countryNode, 'cbc', 'IdentificationCode', strtoupper($country));
    }

    private function vat(\DOMDocument $document, \DOMElement $party, string $vatId): void
    {
        $tax = $this->element($document, $party, 'cac', 'PartyTaxScheme');
        $this->text($document, $tax, 'cbc', 'CompanyID', $vatId);
        $scheme = $this->element($document, $tax, 'cac', 'TaxScheme');
        $this->text($document, $scheme, 'cbc', 'ID', 'VAT');
    }

    private function amount(\DOMDocument $document, \DOMElement $parent, string $name, int $minor, string $currency): void
    {
        $amount = $this->text($document, $parent, 'cbc', $name, ExactMoney::ofMinor($minor, $currency)->decimalString());
        $amount->setAttribute('currencyID', $currency);
    }

    private function percent(int $rateBp): string
    {
        return ExactMoney::ofMinor($rateBp, 'EUR')->decimalString();
    }

    /** @param  array<string, mixed>  $buyer
     * @return array<string, mixed>
     */
    private function buyerAddress(array $buyer): array
    {
        $addresses = $buyer['addresses'] ?? null;

        return is_array($addresses) && is_array($addresses[0] ?? null) ? $addresses[0] : [];
    }

    private function element(\DOMDocument $document, \DOMElement $parent, string $prefix, string $name): \DOMElement
    {
        $element = $document->createElementNS($prefix === 'cac' ? self::CAC : self::CBC, $prefix.':'.$name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(\DOMDocument $document, \DOMElement $parent, string $prefix, string $name, string $value): \DOMElement
    {
        $element = $this->element($document, $parent, $prefix, $name);
        $element->appendChild($document->createTextNode($value));

        return $element;
    }
}

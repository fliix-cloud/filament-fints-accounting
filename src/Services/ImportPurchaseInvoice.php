<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Documents\Data\PurchaseInvoiceUploadResult;
use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Documents\ValidateIncomingEInvoice;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Enums\PartyAddressRole;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Tax\MapImportedEInvoiceTax;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use Illuminate\Support\Str;

final class ImportPurchaseInvoice
{
    public function __construct(
        private readonly ZugferdEInvoiceAdapter $cii,
        private readonly UblEInvoiceParser $ubl,
        private readonly ValidateIncomingEInvoice $conformity,
        private readonly RegisterPurchaseInvoice $invoices,
        private readonly StoreAttachment $attachments,
        private readonly VerifyPurchaseInvoiceOriginals $originals,
        private readonly AccountingAuthorizer $authorizer,
        private readonly LegalEntityScope $entities,
        private readonly PurchaseInvoiceIntakeStore $intakes,
        private readonly AuditLogger $audit,
        private readonly MapImportedEInvoiceTax $taxMapping,
    ) {}

    public function handle(
        LegalEntity $entity,
        string $filename,
        string $contents,
        ?string $xmlFilename = null,
        ?string $xmlContents = null,
    ): PurchaseInvoiceUploadResult {
        $inputs = ['primary' => ['filename' => $filename, 'contents' => $contents]];
        if ($xmlContents !== null) {
            $inputs['companion'] = ['filename' => $xmlFilename ?? '', 'contents' => $xmlContents];
        }
        $intake = $this->intakes->prepare($entity, $inputs);

        return $this->execute($entity, $intake, $inputs);
    }

    public function resume(PurchaseInvoiceIntake $intake): PurchaseInvoiceUploadResult
    {
        $entity = $this->entities->require();
        $this->entities->assertSame($intake->legal_entity_id, $entity);

        return $this->execute($entity, $intake, []);
    }

    /** @param array<string, array{filename: string, contents: string}> $inputs */
    private function execute(LegalEntity $entity, PurchaseInvoiceIntake $intake, array $inputs): PurchaseInvoiceUploadResult
    {
        $this->intakes->authorize($entity);
        $intake = PurchaseInvoiceIntake::query()->where('legal_entity_id', $entity->getKey())->findOrFail($intake->getKey());
        $attempt = (string) Str::uuid();
        $this->audit->log($entity, 'purchase_intake.attempt_started', $intake, correlationId: $attempt);
        try {
            $this->intakes->preserve($entity, $intake, $inputs);

            return $entity->getConnection()->transaction(function () use ($entity, $intake, $attempt): PurchaseInvoiceUploadResult {
                LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $intake = PurchaseInvoiceIntake::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
                $contents = $this->intakes->read($entity, $intake);
                if ($intake->document_id !== null) {
                    $document = Document::query()->where('legal_entity_id', $entity->getKey())->findOrFail($intake->document_id);
                    $this->originals->handle($document);
                    $result = new PurchaseInvoiceUploadResult($document,
                        (bool) data_get($document->e_invoice_meta, 'structured', false),
                        (string) data_get($document->e_invoice_meta, 'format', 'pdf'),
                        (string) data_get($document->e_invoice_meta, 'supplier_match', 'unmatched'), true);
                } else {
                    $result = $this->buildDocument($entity, $intake, $contents);
                }
                $intake->document_id = $result->document->getKey();
                $intake->status = 'complete';
                $intake->last_error = null;
                $intake->save();
                $this->audit->log($entity, 'purchase_intake.completed', $intake, ['document_id' => $intake->document_id], correlationId: $attempt);

                return $result;
            });
        } catch (\Throwable $exception) {
            try {
                $entity->getConnection()->transaction(function () use ($entity, $intake, $exception, $attempt): void {
                    LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                    $locked = PurchaseInvoiceIntake::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
                    $integrityFailed = $exception->getMessage() === __('filament-accounting::errors.attachment_integrity_failed');
                    if ($locked->status !== 'complete' || $integrityFailed) {
                        $locked->status = $locked->preserved_at === null ? 'pending'
                            : ($exception instanceof DocumentException ? 'blocked' : 'failed');
                        if ($integrityFailed) {
                            $locked->status = 'integrity_failed';
                        }
                        $locked->last_error = $exception instanceof DocumentException
                            ? mb_substr($exception->getMessage(), 0, 2000)
                            : __('filament-accounting::errors.intake_processing_failed');
                        $locked->save();
                    }
                    $this->audit->log($entity, 'purchase_intake.failed', $locked, ['exception' => $exception::class], correlationId: $attempt);
                });
            } catch (\Throwable $recordingFailure) {
                report($recordingFailure);
            }
            throw $exception;
        }
    }

    /** @param array<string, string> $inputs */
    private function buildDocument(LegalEntity $entity, PurchaseInvoiceIntake $intake, array $inputs): PurchaseInvoiceUploadResult
    {
        $filename = $intake->files['primary']['filename'];
        $contents = $inputs['primary'];
        $xmlFilename = $intake->files['companion']['filename'] ?? null;
        $xmlContents = $inputs['companion'] ?? null;
        $this->entities->assertSame($entity->getKey());
        $this->authorizer->authorize('register_purchase_invoices', $entity);

        $hash = hash('sha256', $contents);
        $identity = 'intake:'.$intake->uuid;
        if ($xmlContents !== null && (! is_string($xmlFilename)
            || strtolower((string) pathinfo($xmlFilename, PATHINFO_EXTENSION)) !== 'xml')) {
            throw new DocumentException(__('filament-accounting::errors.invalid_e_invoice'));
        }
        [$parsed, $embeddedXml, $format] = $this->inspect($filename, $contents);
        $eInvoiceXml = $embeddedXml;
        $eInvoiceFilename = $embeddedXml !== null
            ? pathinfo($filename, PATHINFO_FILENAME).'-embedded.xml'
            : null;
        $eInvoiceSourceType = 'embedded_e_invoice';
        if ($xmlContents !== null) {
            if (! is_string($xmlFilename) || strtolower((string) pathinfo($xmlFilename, PATHINFO_EXTENSION)) !== 'xml') {
                throw new DocumentException(__('filament-accounting::errors.invalid_e_invoice'));
            }

            $supplied = $this->parseXml($xmlContents, $xmlFilename);
            if ($embeddedXml !== null && ! hash_equals(hash('sha256', $embeddedXml), hash('sha256', $xmlContents))) {
                throw new DocumentException(__('filament-accounting::errors.embedded_xml_mismatch'));
            }

            $parsed = $supplied;
            $eInvoiceXml = $xmlContents;
            $eInvoiceFilename = $xmlFilename;
            $eInvoiceSourceType = $embeddedXml !== null ? 'embedded_e_invoice' : 'supplied_e_invoice';
            $format = ($embeddedXml !== null ? 'hybrid-' : 'pdf+').$supplied->formatKey;
        }
        [$party, $match] = $parsed ? $this->matchSupplier($entity, $parsed) : [null, 'unmatched', false];
        $lines = $parsed ? array_map(
            fn (array $line, int $index): array => $this->importedLine($line, $index + 1),
            $parsed->lines,
            array_keys($parsed->lines),
        ) : [];
        $meta = [
            'structured' => $parsed instanceof EInvoiceParseResult,
            'format' => $format,
            'validated' => $parsed instanceof EInvoiceParseResult,
            'extracted' => $parsed instanceof EInvoiceParseResult,
            'validation_status' => $parsed instanceof EInvoiceParseResult ? 'de_eur_subset_passed' : 'not_checked',
            'intake_id' => $intake->getKey(),
            'original_format' => strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)),
            'supplier_match' => $match,
            'source_sha256' => $hash,
            'structured_sha256' => $eInvoiceXml !== null ? hash('sha256', $eInvoiceXml) : null,
            'source_totals' => $parsed ? [
                'net_minor' => $parsed->netMinor,
                'tax_minor' => $parsed->taxMinor,
                'gross_minor' => $parsed->grossMinor,
            ] : null,
            'document_allowance_charges' => $parsed?->meta['document_allowance_charges'] ?? [],
        ];
        $document = $this->invoices->createDraft($entity, [
            'party_id' => $party?->getKey(),
            'supplier_invoice_number' => $parsed?->documentNumber ?: null,
            'issue_date' => $parsed?->issueDate,
            'currency' => $parsed instanceof EInvoiceParseResult ? $parsed->currency : $entity->base_currency,
            'lines' => $lines,
            'e_invoice_meta' => $meta,
            'idempotency_key' => $identity,
        ]);
        if ($parsed !== null && ($document->net_minor !== $parsed->netMinor
            || $document->tax_minor !== $parsed->taxMinor || $document->gross_minor !== $parsed->grossMinor)) {
            throw new DocumentException(__('filament-accounting::errors.intake_totals_mismatch'));
        }
        $this->linkOriginal($intake, $document, 'primary', 'original_invoice');
        if ($eInvoiceXml !== null && $eInvoiceFilename !== null) {
            if (isset($intake->files['companion'])) {
                $this->linkOriginal($intake, $document, 'companion', $eInvoiceSourceType);
            } elseif (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) === 'xml') {
            } else {
                $this->attachments->handle(
                    $entity,
                    $document,
                    $eInvoiceFilename,
                    $eInvoiceXml,
                    $eInvoiceSourceType,
                    ['format' => $parsed?->formatKey, 'extracted_from_sha256' => $hash],
                );
            }
        }
        $this->originals->handle($document);

        return new PurchaseInvoiceUploadResult($document->fresh(['lines', 'attachments']) ?? $document, $parsed instanceof EInvoiceParseResult, $format, $match);
    }

    private function linkOriginal(PurchaseInvoiceIntake $intake, Document $document, string $role, string $source): void
    {
        $file = $intake->files[$role];
        Attachment::query()->create([
            'legal_entity_id' => $intake->legal_entity_id, 'attachable_type' => $document->getMorphClass(),
            'attachable_id' => $document->getKey(), 'original_filename' => $file['filename'],
            'mime_type' => strtolower((string) pathinfo($file['filename'], PATHINFO_EXTENSION)) === 'xml' ? 'application/xml' : 'application/pdf',
            'size' => $file['size'], 'sha256' => $file['sha256'], 'disk' => $intake->disk, 'path' => $file['path'],
            'source_type' => $source, 'meta' => ['intake_id' => $intake->getKey(), 'role' => $role],
        ]);
    }

    /** @return array{0: EInvoiceParseResult|null, 1: string|null, 2: string} */
    private function inspect(string $filename, string $contents): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'xml') {
            $parsed = $this->parseXml($contents, $filename);

            return [$parsed, $contents, $parsed->formatKey];
        }
        if ($extension === 'pdf') {
            if (! str_starts_with($contents, '%PDF-')) {
                throw new DocumentException(__('filament-accounting::errors.invalid_pdf'));
            }
            try {
                $embedded = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($contents);
            } catch (\Throwable) {
                return [null, null, 'pdf'];
            }
            $parsed = $this->parseXml($embedded, $filename.'#embedded.xml');

            return [$parsed, $embedded, 'hybrid-'.$parsed->formatKey];
        }
        throw new DocumentException(__('filament-accounting::errors.purchase_invoice_pdf_required'));
    }

    private function parseXml(string $contents, string $filename): EInvoiceParseResult
    {
        if (stripos($contents, '<!DOCTYPE') !== false) {
            throw new DocumentException(__('filament-accounting::errors.unsafe_xml'));
        }
        $formatKey = match (true) {
            $this->cii->supports('application/xml', $contents) => 'zugferd',
            $this->ubl->supports($contents) => 'ubl',
            default => null,
        };
        if ($formatKey === null) {
            throw new DocumentException(__('filament-accounting::errors.invalid_e_invoice'));
        }

        $this->conformity->assertSchema($contents, $formatKey);

        $parsed = $formatKey === 'zugferd'
            ? $this->cii->parse($contents, $filename)
            : $this->ubl->parse($contents, $filename);
        if (! $parsed->valid) {
            throw new DocumentException(implode('; ', $parsed->errors));
        }

        $this->conformity->assertBusinessRules($parsed);

        return $parsed;
    }

    /** @return array{0: Party|null, 1: string, 2: bool} */
    private function matchSupplier(LegalEntity $entity, EInvoiceParseResult $parsed): array
    {
        $suppliers = Party::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_supplier', true)
            ->with('taxIds');
        if (filled($parsed->sellerVatId)) {
            $vatId = $this->normalizeIdentifier($parsed->sellerVatId);
            $matches = (clone $suppliers)->whereHas('taxIds')->get()
                ->filter(fn (Party $party): bool => $party->taxIds->contains(
                    fn (PartyTaxId $taxId): bool => $this->normalizeIdentifier($taxId->number) === $vatId
                ));
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched', false];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous', false];
            }
        }
        if (filled($parsed->sellerName)) {
            $needle = $this->normalizeName($parsed->sellerName);
            $matches = $suppliers->get()->filter(fn (Party $party): bool => $this->normalizeName($party->legal_name) === $needle);
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched', false];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous', false];
            }

            $party = Party::query()->create([
                'legal_entity_id' => $entity->getKey(),
                'kind' => 'organization',
                'is_customer' => false,
                'is_supplier' => true,
                'legal_name' => trim((string) $parsed->sellerName),
                'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                'email' => $parsed->sellerEmail,
                'default_currency' => $parsed->currency,
                'is_active' => true,
            ]);

            if (collect([$parsed->sellerAddressLine1, $parsed->sellerAddressLine2, $parsed->sellerPostalCode, $parsed->sellerCity])->filter()->isNotEmpty()) {
                PartyAddress::query()->create([
                    'party_id' => $party->getKey(),
                    'line1' => $parsed->sellerAddressLine1,
                    'line2' => $parsed->sellerAddressLine2,
                    'postal_code' => $parsed->sellerPostalCode,
                    'city' => $parsed->sellerCity,
                    'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                    'address_role' => PartyAddressRole::Both,
                    'is_primary' => true,
                ]);
            }

            if (filled($parsed->sellerVatId)) {
                PartyTaxId::query()->create([
                    'party_id' => $party->getKey(),
                    'type' => 'vat',
                    'number' => trim((string) $parsed->sellerVatId),
                    'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                ]);
            }

            return [$party, 'created', true];
        }

        return [null, 'unmatched', false];
    }

    private function normalizeIdentifier(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $value));
    }

    private function normalizeName(?string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    /** @param array<string, mixed> $line */
    private function importedLine(array $line, int $sourceIndex): array
    {
        $rate = array_key_exists('tax_rate_bp', $line) && $line['tax_rate_bp'] !== null
            ? (int) $line['tax_rate_bp']
            : null;
        $category = filled($line['tax_category'] ?? null) ? (string) $line['tax_category'] : null;
        // Fail closed on unknown EN 16931 rate/category combinations — never invent a tax code.
        $taxCode = $this->taxMapping->code($rate, $category);

        $netMinor = $line['net_minor'] ?? $line['line_net_minor'] ?? null;

        return [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => (string) ($line['quantity'] ?? '1'),
            'unit' => $line['unit'] ?? null,
            'unit_price' => (string) ($line['unit_price'] ?? '0'),
            'net_minor' => $netMinor,
            'tax_code' => $taxCode,
            'imported_tax_code' => $taxCode,
            'imported_tax_rate_bp' => $rate ?? 0,
            'source_line_index' => $sourceIndex,
            'source_line_hash' => hash('sha256', app(CanonicalJson::class)->encode($line)),
            'classification_code' => null,
            'classification_confirmed' => false,
            'tax_confirmed' => false,
        ];
    }
}

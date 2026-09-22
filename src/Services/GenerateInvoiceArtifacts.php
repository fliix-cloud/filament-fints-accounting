<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Documents\ValidateIncomingEInvoice;
use FilamentAccounting\Documents\ValidateOutgoingEInvoice;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdDocumentValidator;
use horstoeko\zugferd\ZugferdXsdValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GenerateInvoiceArtifacts
{
    public function __construct(
        private readonly EInvoiceAdapter $eInvoice,
        private readonly InvoiceRenderer $renderer,
        private readonly VerifyAttachmentIntegrity $integrity,
        private readonly AccountingAuthorizer $authorizer,
        private readonly LegalEntityScope $entities,
        private readonly AuditLogger $audit,
        private readonly CanonicalJson $canonical,
        private readonly AuditEventHasher $eventHasher,
        private readonly ValidateOutgoingEInvoice $outgoing,
        private readonly ValidateIncomingEInvoice $incoming,
    ) {}

    /** @return array{pdf: Attachment, xml: Attachment} */
    public function handle(Document $document): array
    {
        $entity = $this->entities->require();
        $this->entities->assertSame($document->legal_entity_id, $entity);
        $this->authorizer->authorize('issue_invoices', $entity);
        if ($entity->getConnection()->transactionLevel() !== 0) {
            throw new DocumentException(__('filament-accounting::errors.artifacts_require_independent_commit'));
        }
        $document = Document::query()->where('legal_entity_id', $entity->getKey())->with('lines')->findOrFail($document->getKey());
        $attempt = (string) Str::uuid();
        $this->audit->log($entity, 'invoice_artifacts.attempt_started', $document, correlationId: $attempt);
        try {
            $set = $this->prepare($entity, $document);
            foreach (['xml', 'pdf'] as $role) {
                $entity->getConnection()->transaction(function () use ($entity, $document, $set, $role): void {
                    LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                    $locked = InvoiceArtifactSet::query()->whereKey($set->getKey())->lockForUpdate()->firstOrFail();
                    $current = Document::query()->with('lines')->findOrFail($document->getKey());
                    $this->assertEvidence($current, $locked);
                    $file = $locked->manifest[$role];
                    $disk = Storage::disk($locked->disk);
                    if (! ($locked->preserved_roles[$role] ?? false) && ! $disk->exists($file['path'])) {
                        $bytes = $role === 'xml' ? $locked->xml : base64_decode($locked->pdf_base64, true);
                        if (! is_string($bytes) || ! $disk->put($file['path'], $bytes, ['visibility' => 'private'])) {
                            throw new DocumentException(__('filament-accounting::errors.attachment_write_failed'));
                        }
                    }
                    $stored = $disk->get($file['path']);
                    if (! is_string($stored) || strlen($stored) !== $file['size'] || hash('sha256', $stored) !== $file['sha256']) {
                        throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
                    }
                    $attachments = $current->attachments()->where('source_type', 'generated_'.$role)->get();
                    if ($attachments->count() > 1) {
                        throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
                    }
                    if ($attachments->isEmpty()) {
                        Attachment::query()->create([
                            'legal_entity_id' => $entity->getKey(), 'attachable_type' => $current->getMorphClass(),
                            'attachable_id' => $current->getKey(), 'original_filename' => $file['filename'],
                            'mime_type' => $role === 'xml' ? 'application/xml' : 'application/pdf',
                            'size' => $file['size'], 'sha256' => $file['sha256'], 'disk' => $locked->disk,
                            'path' => $file['path'], 'source_type' => 'generated_'.$role,
                            'structured_payload' => $role === 'xml' ? $locked->xml : null,
                            'meta' => $locked->meta + ($role === 'pdf' ? [
                                'embedded_xml_sha256' => $locked->manifest['xml']['sha256'], 'pdfa_part' => 3, 'pdfa_conformance' => 'B',
                            ] : []),
                        ]);
                    } else {
                        $this->assertAttachment($attachments->first(), $locked, $role);
                    }
                    $locked->preserved_roles = $locked->preserved_roles + [$role => true];
                    if (count($locked->preserved_roles) === 2 && $locked->completed_at === null) {
                        $locked->completed_at = now();
                    }
                    $locked->save();
                    $this->audit->log($entity, 'invoice_artifacts.file_preserved', $current, ['role' => $role, 'sha256' => $file['sha256']]);
                });
            }
            $this->verify($document);
            $this->audit->log($entity, 'invoice_artifacts.completed', $document, correlationId: $attempt);
        } catch (\Throwable $exception) {
            try {
                $this->audit->log($entity, 'invoice_artifacts.failed', $document, ['exception' => $exception::class], correlationId: $attempt);
            } catch (\Throwable $loggingFailure) {
                report($loggingFailure);
            }
            throw $exception;
        }

        return [
            'pdf' => Attachment::query()->where('attachable_type', $document->getMorphClass())->where('attachable_id', $document->getKey())->where('source_type', 'generated_pdf')->sole(),
            'xml' => Attachment::query()->where('attachable_type', $document->getMorphClass())->where('attachable_id', $document->getKey())->where('source_type', 'generated_xml')->sole(),
        ];
    }

    public function verify(Document $document): void
    {
        $document = Document::query()->with('lines')->findOrFail($document->getKey());
        $set = InvoiceArtifactSet::query()->where('document_id', $document->getKey())->first();
        if (! $set instanceof InvoiceArtifactSet || $set->completed_at === null) {
            throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
        }
        $this->assertEvidence($document, $set);
        foreach (['pdf', 'xml'] as $role) {
            $attachments = $document->attachments()->where('source_type', 'generated_'.$role)->get();
            if ($attachments->count() !== 1 || ! ($set->preserved_roles[$role] ?? false)) {
                throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
            }
            $this->assertAttachment($attachments->first(), $set, $role);
        }
    }

    /** Read-only inspection of an interrupted or completed set; never renders or repairs. */
    public function verifyPreservedSet(Document $document, InvoiceArtifactSet $set): void
    {
        $document = Document::query()->with('lines')->findOrFail($document->getKey());
        $this->assertEvidence($document, $set);
        if (array_diff_key($set->preserved_roles, ['pdf' => true, 'xml' => true]) !== []) {
            throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
        $events = AuditEvent::query()->where('legal_entity_id', $set->legal_entity_id)
            ->where('target_type', $document->getMorphClass())->where('target_id', (string) $document->getKey())
            ->where('operation', 'invoice_artifacts.file_preserved')->get();
        foreach (['pdf', 'xml'] as $role) {
            $file = $set->manifest[$role];
            $preserved = $set->preserved_roles[$role] ?? false;
            $recorded = $events->filter(fn (AuditEvent $event): bool => ($event->payload['role'] ?? null) === $role);
            if ($preserved !== $recorded->isNotEmpty()
                || $recorded->contains(fn (AuditEvent $event): bool => ($event->payload['sha256'] ?? null) !== $file['sha256'])) {
                throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
            }
            $disk = Storage::disk($set->disk);
            if ($preserved || $disk->exists($file['path'])) {
                $bytes = $disk->get($file['path']);
                if (! is_string($bytes) || strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['sha256']) {
                    throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
                }
            }
            $attachments = $document->attachments()->where('source_type', 'generated_'.$role)->get();
            if ($preserved || $attachments->isNotEmpty()) {
                if ($attachments->count() !== 1) {
                    throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
                }
                $this->assertAttachment($attachments->first(), $set, $role);
            }
        }
        if (($set->completed_at !== null) !== (($set->preserved_roles['pdf'] ?? false) && ($set->preserved_roles['xml'] ?? false))) {
            throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
    }

    private function assertAttachment(?Model $attachment, InvoiceArtifactSet $set, string $role): void
    {
        $file = $set->manifest[$role];
        $meta = $set->meta + ($role === 'pdf' ? [
            'embedded_xml_sha256' => $set->manifest['xml']['sha256'], 'pdfa_part' => 3, 'pdfa_conformance' => 'B',
        ] : []);
        if (! $attachment instanceof Attachment || $attachment->disk !== $set->disk
            || $attachment->legal_entity_id !== $set->legal_entity_id || $attachment->path !== $file['path']
            || $attachment->sha256 !== $file['sha256'] || $attachment->size !== $file['size']
            || $attachment->original_filename !== $file['filename']
            || $attachment->mime_type !== ($role === 'xml' ? 'application/xml' : 'application/pdf')
            || ($role === 'xml' && $attachment->structured_payload !== $set->xml)
            || $this->canonical->encode($attachment->meta) !== $this->canonical->encode($meta)) {
            throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
        $this->integrity->handle($attachment);
    }

    private function assertEvidence(Document $document, InvoiceArtifactSet $set): void
    {
        $evidence = hash('sha256', $this->canonical->encode([$set->disk, $set->manifest, $set->snapshot, $set->meta]));
        $events = AuditEvent::query()->where('legal_entity_id', $document->legal_entity_id)
            ->where('target_type', $document->getMorphClass())->where('target_id', (string) $document->getKey())
            ->where('operation', 'invoice_artifacts.prepared')->get();
        $pdf = base64_decode($set->pdf_base64, true);
        $event = $events->first();
        if ($document->legal_entity_id !== $set->legal_entity_id || $document->getKey() !== $set->document_id
            || $document->type !== DocumentType::SalesInvoice || $document->document_status !== DocumentStatus::Issued
            || ($document->corrected_document_id === null && $document->invoice_version !== 1)
            || $evidence !== $set->evidence_sha256 || $events->count() !== 1
            || ! $event instanceof AuditEvent
            || data_get($event->payload, 'evidence_sha256') !== $evidence
            || $this->canonical->encode($event->payload) !== $event->canonical_payload
            || $this->eventHasher->hash($event->getAttributes()) !== $event->event_hash
            || $this->canonical->encode($this->snapshot($document)) !== $this->canonical->encode($set->snapshot)
            || ! is_string($pdf) || hash('sha256', $pdf) !== ($set->manifest['pdf']['sha256'] ?? null)
            || strlen($pdf) !== ($set->manifest['pdf']['size'] ?? null)
            || hash('sha256', $set->xml) !== ($set->manifest['xml']['sha256'] ?? null)
            || strlen($set->xml) !== ($set->manifest['xml']['size'] ?? null)) {
            throw new DocumentException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
    }

    private function prepare(LegalEntity $entity, Document $document): InvoiceArtifactSet
    {
        return $entity->getConnection()->transaction(function () use ($entity, $document): InvoiceArtifactSet {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $document = Document::query()->with('lines')->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            if ($document->type !== DocumentType::SalesInvoice || $document->document_status !== DocumentStatus::Issued) {
                throw new DocumentException(__('filament-accounting::errors.only_issued_sales_invoice_exportable'));
            }
            $existing = InvoiceArtifactSet::query()->where('document_id', $document->getKey())->first();
            if ($existing instanceof InvoiceArtifactSet) {
                $this->assertEvidence($document, $existing);

                return $existing;
            }
            if ($document->attachments()->whereIn('source_type', ['generated_pdf', 'generated_xml'])->exists()
                || AuditEvent::query()->where('legal_entity_id', $entity->getKey())->where('target_type', $document->getMorphClass())
                    ->where('target_id', (string) $document->getKey())->where('operation', 'invoice_artifacts.prepared')->exists()) {
                throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
            }
            $snapshot = $this->snapshot($document);
            $this->outgoing->assert($snapshot);
            $xml = $this->eInvoice->generate($snapshot);
            $this->validateXml($xml);
            $this->assertReceptionRoundTrip($xml);
            $pdf = (new ZugferdDocumentPdfMerger($xml, $this->renderer->render($snapshot)))->generateDocument()->downloadString();
            if ($xml !== ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($pdf)) {
                throw new DocumentException(__('filament-accounting::errors.embedded_xml_mismatch'));
            }
            $disk = (string) config('filament-accounting.storage.disk', 'local');
            if ($disk === 'public') {
                throw new DocumentException(__('filament-accounting::errors.public_disk_forbidden'));
            }
            $directory = trim((string) config('filament-accounting.storage.attachments_directory', 'accounting/attachments'), '/');
            $prefix = $directory.'/issued/'.Str::uuid();
            $manifest = [];
            foreach (['xml' => $xml, 'pdf' => $pdf] as $role => $bytes) {
                if ($bytes === '' || strlen($bytes) > (int) config('filament-accounting.storage.maximum_attachment_bytes', 15 * 1024 * 1024)) {
                    throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
                }
                $manifest[$role] = ['path' => $prefix.'/'.$role.'.'.$role, 'filename' => 'invoice-'.$document->uuid.'.'.$role,
                    'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
            }
            $meta = ['generated_at' => now()->toIso8601String(), 'profile' => (string) $snapshot['e_invoice_profile'],
                'validation_status' => 'de_eur_subset_passed',
                'renderer' => $this->renderer->key(), 'renderer_version' => $this->renderer->version(),
                'template' => $snapshot['seller']['invoice_template_key'] ?? 'default',
                'template_version' => $snapshot['seller']['invoice_template_version'] ?? $this->renderer->version()];
            $evidence = hash('sha256', $this->canonical->encode([$disk, $manifest, $snapshot, $meta]));
            $set = InvoiceArtifactSet::query()->create([
                'legal_entity_id' => $entity->getKey(), 'document_id' => $document->getKey(), 'disk' => $disk,
                'manifest' => $manifest, 'snapshot' => $snapshot, 'meta' => $meta, 'evidence_sha256' => $evidence,
                'pdf_base64' => base64_encode($pdf), 'xml' => $xml, 'preserved_roles' => [],
            ]);
            $this->audit->log($entity, 'invoice_artifacts.prepared', $document, ['evidence_sha256' => $evidence, 'manifest' => $manifest]);

            return $set;
        });
    }

    private function validateXml(string $xml): void
    {
        try {
            $invoice = ZugferdDocumentReader::readAndGuessFromContent($xml);
            $schema = (new ZugferdXsdValidator($invoice))->validate();
            $businessRuleViolations = (new ZugferdDocumentValidator($invoice))->validateDocument();
        } catch (\Throwable $exception) {
            throw new DocumentException(__('filament-accounting::errors.invalid_generated_e_invoice', [
                'detail' => $exception->getMessage() !== '' ? $exception->getMessage() : 'schema or business-rule validation failed',
            ]), previous: $exception);
        }

        $details = [];
        foreach ($schema->validationErrors() as $error) {
            $details[] = trim((string) $error);
            if (count($details) >= 3) {
                break;
            }
        }
        if ($details === []) {
            foreach ($businessRuleViolations as $violation) {
                $details[] = trim($violation->getMessage());
                if (count($details) >= 3) {
                    break;
                }
            }
        }
        if ($schema->hasValidationErrors() || count($businessRuleViolations) > 0) {
            throw new DocumentException(__('filament-accounting::errors.invalid_generated_e_invoice', [
                'detail' => $details === [] ? 'schema or business-rule validation failed' : implode('; ', $details),
            ]));
        }
    }

    private function assertReceptionRoundTrip(string $xml): void
    {
        try {
            $this->incoming->assertSchema($xml, 'zugferd');
            $parsed = $this->eInvoice->parse($xml, 'issued-invoice.xml');
            if (! $parsed->valid) {
                throw new DocumentException(implode('; ', $parsed->errors));
            }
            $this->incoming->assertBusinessRules($parsed);
        } catch (DocumentException $exception) {
            throw new DocumentException(__('filament-accounting::errors.e_invoice_issuing_subset_failed', [
                'detail' => $exception->getMessage(),
            ]), previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(Document $document): array
    {
        $buyerSnapshot = $document->party_snapshot ?? [];
        $buyerReference = $buyerSnapshot['external_reference'] ?? null;

        return [
            'number' => $document->number,
            'issue_date' => $document->issue_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'currency' => $document->currency,
            'net_minor' => $document->net_minor,
            'tax_minor' => $document->tax_minor,
            'gross_minor' => $document->gross_minor,
            'seller' => $document->legal_entity_snapshot ?? [],
            'buyer' => $document->party_snapshot ?? [],
            'seller_name' => (string) (($document->legal_entity_snapshot ?? [])['legal_name'] ?? ''),
            'buyer_name' => (string) ($buyerSnapshot['legal_name'] ?? ''),
            'e_invoice_profile' => $this->outgoing->profile(),
            'buyer_reference' => filled($buyerReference) ? (string) $buyerReference : null,
            ...($document->payment_method === null ? [] : [
                'payment' => $document->payment_snapshot ?? [],
                'supply_date' => $document->supply_date?->toDateString(),
            ]),
            'lines' => $document->lines->map(fn (DocumentLine $line): array => [
                ...($document->payment_method === null ? [] : ['sku' => $line->catalog_sku]),
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price_minor' => $line->unit_price_minor,
                'net_minor' => $line->net_minor,
                'tax_minor' => $line->tax_minor,
                'gross_minor' => $line->gross_minor,
                'tax_rate_bp' => $line->tax_rate_bp,
                'tax_category' => $line->tax_category,
                'tax_reason' => $line->tax_reason,
            ])->all(),
            ...($document->corrected_document_id === null ? [] : [
                'invoice_version' => $document->invoice_version,
                'previous_invoice_version' => $document->correctedDocument?->invoice_version,
                'preceding_invoice_number' => $document->correctedDocument?->number,
                'preceding_invoice_date' => $document->correctedDocument?->issue_date?->toDateString(),
                'correction_reason' => data_get($document->e_invoice_meta, 'correction_reason'),
            ]),
        ];
    }
}

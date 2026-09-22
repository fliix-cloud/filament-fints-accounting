<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Documents\ValidateIncomingEInvoice;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ViewSalesInvoice;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\PostDocument;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Tests\TestCase;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdDocumentValidator;
use horstoeko\zugferd\ZugferdXsdValidator;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class InvoiceArtifactTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function evidenceMutations(): array
    {
        return array_combine($names = ['missing_file', 'changed_snapshot', 'changed_staged_pdf', 'reset_preservation', 'deleted_set'], array_map(fn (string $name): array => [$name], $names));
    }

    #[Test]
    #[DataProvider('evidenceMutations')]
    public function scheduled_verification_detects_outgoing_evidence_tampering(string $mutation): void
    {
        $document = $this->issuedWithoutArtifacts();
        app(GenerateInvoiceArtifacts::class)->handle($document);
        auth()->forgetGuards();
        $this->assertSame(0, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $set = InvoiceArtifactSet::query()->sole();
        $row = DB::table('accounting_invoice_artifact_sets')->where('id', $set->getKey());
        switch ($mutation) {
            case 'missing_file':
                Storage::disk($set->disk)->delete($set->manifest['pdf']['path']);
                break;
            case 'changed_snapshot':
                DB::table('accounting_document_lines')->where('document_id', $document->getKey())->update(['description' => 'Changed']);
                break;
            case 'changed_staged_pdf':
                $row->update(['pdf_base64' => base64_encode('changed')]);
                break;
            case 'reset_preservation':
                $row->update(['preserved_roles' => '[]', 'completed_at' => null]);
                break;
            case 'deleted_set':
                DB::table('accounting_attachments')->delete();
                $row->delete();
                break;
        }
        $this->assertTrue(app(AuditChainVerifier::class)->verify($document->legal_entity_id)->isValid());
        $this->assertSame(1, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($report['legal_entities'][0]['invoice_evidence']['issues']);
        $this->assertSame(1, Artisan::call('filament-accounting:verify'));
        $this->assertStringContainsString('Invoice evidence [', Artisan::output());
    }

    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function separate_accounting_connection_keeps_issuance_and_staging_recoverable_after_failure(): void
    {
        config()->set('database.connections.artifact_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('artifact_accounting')->getSchemaBuilder());
        try {
            (require __DIR__.'/../../database/migrations/2026_08_30_000001_create_filament_accounting_tables.php')->up();
            (require __DIR__.'/../../database/migrations/2026_08_31_000002_create_accounting_party_bank_accounts.php')->up();
            (require __DIR__.'/../../database/migrations/2026_09_01_000003_create_filament_accounting_banking_tables.php')->up();
            (require __DIR__.'/../../database/migrations/2026_09_04_000005_add_party_contact_columns.php')->up();
            (require __DIR__.'/../../database/migrations/2026_09_10_000002_add_invoice_versions.php')->up();
            (require __DIR__.'/../../database/migrations/2026_09_10_000003_add_invoice_payment_and_layout_fields.php')->up();
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'artifact_accounting');
        $document = $this->draftForIssuance();
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $fail = true;
        Attachment::creating(function (Attachment $attachment) use (&$fail): void {
            if ($fail && $attachment->source_type === 'generated_pdf') {
                throw new \RuntimeException('PDF metadata failure');
            }
        });
        try {
            app(IssueSalesInvoice::class)->issue($document);
            $this->fail('Failure must leave a recoverable issued invoice.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PDF metadata failure', $exception->getMessage());
        }
        $this->assertSame(DocumentStatus::Issued, $document->fresh()->document_status);
        $this->assertSame(1, InvoiceArtifactSet::query()->count());
        $this->assertSame(1, Attachment::query()->count());
        $this->assertSame(0, DB::connection('artifact_accounting')->table('accounting_journal_entries')->count());
        $inspection = app(InvoiceEvidenceVerifier::class)->verify($document->legal_entity_id);
        $this->assertSame([], $inspection['issues']);
        $this->assertCount(1, $inspection['pending']);
        $this->assertDatabaseCount('accounting_documents', 0);
        $this->assertDatabaseCount('accounting_invoice_artifact_sets', 0);
        $fail = false;
        app(IssueSalesInvoice::class)->issue($document);
        $this->assertSame(PostingStatus::Posted, $document->fresh()->posting_status);
        $this->assertSame(1, DB::connection('artifact_accounting')->table('accounting_journal_entries')->count());
        $this->assertSame(1, AuditEvent::query()->where('operation', 'document.issued')->count());
        $this->assertDatabaseCount('accounting_journal_entries', 0);
        $this->assertSame([], app(InvoiceEvidenceVerifier::class)->verify($document->legal_entity_id)['issues']);
    }

    #[Test]
    public function failed_pdf_write_resumes_from_staged_bytes_after_renderer_upgrade(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $disk = Storage::disk('accounting-artifacts');
        $fail = true;
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(function (string $path, string $contents, array $options) use ($disk, &$fail): bool {
            return $fail && str_ends_with($path, '.pdf') ? false : $disk->put($path, $contents, $options);
        });
        Storage::shouldReceive('disk')->with('accounting-artifacts')->andReturn($proxy);
        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('Storage failure must be reported.');
        } catch (AccountingException $exception) {
            $this->assertSame(__('filament-accounting::errors.attachment_write_failed'), $exception->getMessage());
        }
        $set = InvoiceArtifactSet::query()->sole();
        $this->assertSame(['xml' => true], $set->preserved_roles);
        $this->assertNull($set->completed_at);
        $this->assertFalse($disk->exists($set->manifest['pdf']['path']));
        $inspection = app(InvoiceEvidenceVerifier::class)->verify($document->legal_entity_id);
        $this->assertSame([], $inspection['issues']);
        $this->assertCount(1, $inspection['pending']);
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);
        $fail = false;
        app(GenerateInvoiceArtifacts::class)->handle($document);
        $this->assertSame(base64_decode($set->pdf_base64, true), $disk->get($set->manifest['pdf']['path']));
        $this->assertNotNull($set->fresh()->completed_at);
    }

    #[Test]
    public function altered_preparation_event_payload_is_rejected_even_when_its_manifest_digest_is_unchanged(): void
    {
        $document = $this->issuedWithoutArtifacts();
        app(GenerateInvoiceArtifacts::class)->handle($document);
        $event = AuditEvent::query()->where('operation', 'invoice_artifacts.prepared')->sole();
        DB::table('accounting_audit_events')->where('id', $event->getKey())
            ->update(['payload' => json_encode($event->payload + ['changed' => true], JSON_THROW_ON_ERROR)]);
        $this->expectExceptionMessage(__('filament-accounting::errors.attachment_integrity_failed'));
        app(GenerateInvoiceArtifacts::class)->handle($document);
    }

    #[Test]
    public function missing_attachment_rows_are_recovered_but_missing_authoritative_set_blocks_regeneration(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $first = app(GenerateInvoiceArtifacts::class)->handle($document);
        $disk = Storage::disk('accounting-artifacts');
        $files = $disk->allFiles();
        DB::table('accounting_attachments')->delete();
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);
        $retry = app(GenerateInvoiceArtifacts::class)->handle($document);
        foreach (['xml', 'pdf'] as $role) {
            $this->assertSame($first[$role]->path, $retry[$role]->path);
            $this->assertSame($first[$role]->sha256, $retry[$role]->sha256);
        }
        $this->assertSame($files, $disk->allFiles());
        DB::table('accounting_attachments')->delete();
        DB::table('accounting_invoice_artifact_sets')->delete();
        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('The prepared audit event must prevent a second authoritative set.');
        } catch (AccountingException $exception) {
            $this->assertSame(__('filament-accounting::errors.invoice_originals_incomplete'), $exception->getMessage());
        }
        $this->assertSame($files, $disk->allFiles());
        $this->assertDatabaseCount('accounting_invoice_artifact_sets', 0);
    }

    #[Test]
    public function changed_document_or_staged_bytes_block_retry_and_posting_even_with_stale_models(): void
    {
        $document = $this->issuedWithoutArtifacts();
        app(GenerateInvoiceArtifacts::class)->handle($document);
        $document->load('lines');
        $set = InvoiceArtifactSet::query()->sole();
        DB::table('accounting_document_lines')->where('document_id', $document->getKey())->update(['description' => 'Changed after issuance']);
        foreach (['retry', 'post'] as $operation) {
            try {
                $operation === 'retry' ? app(GenerateInvoiceArtifacts::class)->handle($document) : app(PostDocument::class)->handle($document);
                $this->fail('Changed source content must not pass verification.');
            } catch (AccountingException $exception) {
                $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
            }
        }
        DB::table('accounting_document_lines')->where('document_id', $document->getKey())->update(['description' => 'Consulting']);
        DB::table('accounting_invoice_artifact_sets')->where('id', $set->getKey())->update(['pdf_base64' => base64_encode('replacement')]);
        $this->expectExceptionMessage(__('filament-accounting::errors.attachment_integrity_failed'));
        app(GenerateInvoiceArtifacts::class)->handle($document);
    }

    #[Test]
    public function generated_originals_and_authoritative_set_cannot_be_deleted_through_models(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $artifacts = app(GenerateInvoiceArtifacts::class)->handle($document);
        foreach ([$artifacts['xml'], $artifacts['pdf'], InvoiceArtifactSet::query()->sole()] as $record) {
            try {
                $record->delete();
                $this->fail('Authoritative evidence must be retained.');
            } catch (PostedRecordImmutableException) {
                $this->assertTrue($record->fresh()->exists);
            }
        }
    }

    #[Test]
    public function filament_completes_interrupted_issuance_once_despite_changed_renderer_and_configuration(): void
    {
        $document = $this->draftForIssuance();
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $fail = true;
        Attachment::creating(function (Attachment $attachment) use (&$fail): void {
            if ($fail && $attachment->source_type === 'generated_pdf') {
                throw new \RuntimeException('PDF metadata failure');
            }
        });
        try {
            app(IssueSalesInvoice::class)->issue($document);
            $this->fail('Issuance must report its interrupted file step.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PDF metadata failure', $exception->getMessage());
        }
        $document->refresh();
        $number = $document->number;
        $this->assertSame(DocumentStatus::Issued, $document->document_status);
        $this->assertSame(PostingStatus::Unposted, $document->posting_status);
        $this->assertTrue($document->e_invoice_meta['artifacts_required']);
        $set = InvoiceArtifactSet::query()->sole();
        $originalPdf = base64_decode($set->pdf_base64, true);
        config()->set('filament-accounting.e_invoice.generate_on_issue', false);
        $fail = false;
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        Livewire::test(ListSalesInvoices::class)->callTableAction('completeIssuance', $document)
            ->assertHasNoTableActionErrors()->assertRedirect(SalesInvoiceResource::getUrl('view', ['record' => $document]));
        $document->refresh();
        $this->assertSame(PostingStatus::Posted, $document->posting_status);
        $this->assertSame($number, $document->number);
        $this->assertSame($originalPdf, Storage::disk($set->disk)->get($set->manifest['pdf']['path']));
        app(IssueSalesInvoice::class)->issue($document);
        $this->assertDatabaseCount('accounting_journal_entries', 1);
        $this->assertSame(1, AuditEvent::query()->where('operation', 'document.issued')->count());
        Livewire::test(ViewSalesInvoice::class, ['record' => $document->getRouteKey()])->assertActionHidden('completeIssuance');
    }

    #[Test]
    public function ambient_transactions_and_denied_actors_cannot_start_artifact_generation(): void
    {
        $document = $this->issuedWithoutArtifacts();
        try {
            $document->getConnection()->transaction(fn () => app(GenerateInvoiceArtifacts::class)->handle($document));
            $this->fail('Artifact staging needs its own commit.');
        } catch (AccountingException $exception) {
            $this->assertSame(__('filament-accounting::errors.artifacts_require_independent_commit'), $exception->getMessage());
        }
        Gate::define(config('filament-accounting.authorization.abilities.issue_invoices'), fn () => false);
        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('Generation requires issuance permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('accounting_invoice_artifact_sets', 0);
            $this->assertSame([], Storage::disk('accounting-artifacts')->allFiles());
        }
    }

    #[Test]
    public function pdf_failure_retains_exact_bytes_and_retry_completes_without_rendering_again(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $failure = new \RuntimeException('PDF metadata unavailable');
        $fail = true;
        Attachment::creating(function (Attachment $attachment) use ($failure, &$fail): void {
            if ($fail && $attachment->source_type === 'generated_pdf') {
                throw $failure;
            }
        });
        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('The PDF failure must propagate unchanged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $xml = $document->attachments()->sole();
        $this->assertSame('generated_xml', $xml->source_type);
        $original = app(ReadAttachment::class)->handle($xml);
        $disk = Storage::disk('accounting-artifacts');
        $files = $disk->allFiles();
        $this->assertCount(2, $files);
        $retained = array_map(fn (string $path) => $disk->get($path), $files);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(GenerateInvoiceArtifacts::class)->handle($document);
                $this->fail('A partial pair must not be regenerated or reported complete.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
            $this->assertSame($original, $disk->get($xml->path));
            $this->assertSame($retained, array_map(fn (string $path) => $disk->get($path), $files));
            $this->assertSame($files, $disk->allFiles());
            $this->assertDatabaseCount('accounting_attachments', 1);
        }
        $fail = false;
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);
        $result = app(GenerateInvoiceArtifacts::class)->handle($document);
        $this->assertSame($xml->getKey(), $result['xml']->getKey());
        $this->assertSame($retained, array_map(fn (string $path) => $disk->get($path), $files));
        $this->assertDatabaseCount('accounting_attachments', 2);
    }

    #[Test]
    public function artifact_retry_checks_both_files_and_never_replaces_missing_or_corrupt_bytes(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $service = app(GenerateInvoiceArtifacts::class);
        $artifacts = $service->handle($document);
        $disk = Storage::disk('accounting-artifacts');
        foreach ($artifacts as $attachment) {
            $original = $disk->get($attachment->path);
            foreach (['corrupted', null] as $contents) {
                $contents === null ? $disk->delete($attachment->path) : $disk->put($attachment->path, $contents);
                try {
                    $service->handle($document);
                    $this->fail('Broken evidence must block generation.');
                } catch (AccountingException $exception) {
                    $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
                }
                $this->assertSame($contents, $disk->get($attachment->path));
                $this->assertDatabaseCount('accounting_attachments', 2);
            }
            $disk->put($attachment->path, $original);
        }
    }

    #[Test]
    public function renderer_upgrade_reuses_the_verified_issued_pair(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $first = app(GenerateInvoiceArtifacts::class)->handle($document);
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldReceive('version')->andReturn('2');
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);

        $retry = app(GenerateInvoiceArtifacts::class)->handle($document);
        foreach (['pdf', 'xml'] as $type) {
            $this->assertSame($first[$type]->getKey(), $retry[$type]->getKey());
            $this->assertSame($first[$type]->path, $retry[$type]->path);
            $this->assertSame($first[$type]->sha256, $retry[$type]->sha256);
        }
        $this->assertDatabaseCount('accounting_attachments', 2);
    }

    private function issuedWithoutArtifacts(): Document
    {
        return app(IssueSalesInvoice::class)->issue($this->draftForIssuance(), false);
    }

    private function draftForIssuance(): Document
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.generate_on_issue', false);
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1', 'postal_code' => '10115',
            'city' => 'Berlin', 'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser());

        return app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19']],
        ]);
    }

    #[Test]
    public function issuing_produces_private_pdfa3_and_byte_identical_invoice_xml(): void
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'vat_id' => 'DE123456789',
            'invoice_iban' => 'DE89370400440532013000',
            'invoice_bic' => 'COBADEFFXXX',
            'invoice_template_key' => 'default',
            'invoice_template_version' => '1',
        ]);
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [
                ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19'],
                ['description' => 'Books', 'quantity' => '1', 'unit_price' => '20.00', 'tax_code' => 'DE-7'],
            ],
        ], false);

        $artifacts = $document->attachments()->get()->keyBy('source_type');
        $this->assertCount(2, $artifacts);
        $pdfAttachment = $artifacts->get('generated_pdf');
        $xmlAttachment = $artifacts->get('generated_xml');
        $this->assertNotNull($pdfAttachment);
        $this->assertNotNull($xmlAttachment);
        $pdf = app(ReadAttachment::class)->handle($pdfAttachment);
        $xml = app(ReadAttachment::class)->handle($xmlAttachment);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertStringContainsString('/Type /OutputIntent', $pdf);
        $this->assertStringContainsString('/AFRelationship /Data', $pdf);
        $this->assertStringContainsString('factur-x.xml', $pdf);
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
        $invoice = ZugferdDocumentReader::readAndGuessFromContent($xml);
        $this->assertTrue((new ZugferdXsdValidator($invoice))->validate()->hasNoValidationErrors());
        $this->assertCount(0, (new ZugferdDocumentValidator($invoice))->validateDocument());
        $embedded = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($pdf);
        $this->assertSame($xml, $embedded);
        $incoming = app(ValidateIncomingEInvoice::class);
        $adapter = app(ZugferdEInvoiceAdapter::class);
        $incoming->assertSchema($xml, 'zugferd');
        $incoming->assertBusinessRules($adapter->parse($xml, 'invoice.xml'));
        $incoming->assertSchema($embedded, 'zugferd');
        $incoming->assertBusinessRules($adapter->parse($embedded, 'embedded.xml'));
        $this->assertSame('de_eur_subset_passed', $pdfAttachment->meta['validation_status']);
        $this->assertSame('en16931', $pdfAttachment->meta['profile']);
        $this->assertSame('de_eur_subset_passed', $xmlAttachment->meta['validation_status']);
        $this->assertStringNotContainsString('certified', strtolower(json_encode($pdfAttachment->meta, JSON_THROW_ON_ERROR)));
        $this->assertSame(hash('sha256', $xml), $pdfAttachment->meta['embedded_xml_sha256']);
        $this->assertSame(3, $pdfAttachment->meta['pdfa_part']);
        $this->assertSame('1', $pdfAttachment->meta['renderer_version']);

        app(GenerateInvoiceArtifacts::class)->handle($document);
        $this->assertSame(2, $document->attachments()->count());
    }
}

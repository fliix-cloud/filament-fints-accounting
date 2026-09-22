<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ListPurchaseInvoiceIntakes;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\PurchaseInvoiceIntakeStore;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class PurchaseInvoiceUploadTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        // Exercise real commits: intake must reject an enclosing transaction.
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function separate_accounting_connection_preserves_intake_while_rolling_back_supplier_and_draft(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        config()->set('database.connections.intake_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('intake_accounting')->getSchemaBuilder());
        try {
            (require __DIR__.'/../../database/migrations/2026_08_30_000001_create_filament_accounting_tables.php')->up();
            (require __DIR__.'/../../database/migrations/2026_09_04_000005_add_party_contact_columns.php')->up();
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'intake_accounting');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $fail = true;
        Attachment::creating(function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Attachment save failed');
            }
        });
        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.xml', $this->ublInvoice());
            $this->fail('The business transaction must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Attachment save failed', $exception->getMessage());
            $this->assertSame(0, DB::connection('intake_accounting')->table('accounting_documents')->count());
            $this->assertSame(0, DB::connection('intake_accounting')->table('accounting_parties')->count());
            $this->assertSame(1, DB::connection('intake_accounting')->table('accounting_purchase_invoice_intakes')->count());
            $this->assertDatabaseCount('accounting_documents', 0);
            $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 0);
        }
        $intake = PurchaseInvoiceIntake::query()->sole();
        $this->assertSame($this->ublInvoice(), Storage::disk($intake->disk)->get($intake->files['primary']['path']));
        $fail = false;
        app(ImportPurchaseInvoice::class)->resume($intake);
        $this->assertSame(1, DB::connection('intake_accounting')->table('accounting_documents')->count());
        $this->assertSame(1, DB::connection('intake_accounting')->table('accounting_parties')->count());
        $this->assertSame(1, AuditEvent::query()->where('operation', 'purchase_intake.completed')->count());
        $inspection = app(InvoiceEvidenceVerifier::class)->verify((int) $entity->getKey());
        $this->assertSame([], $inspection['issues']);
        $this->assertSame([], $inspection['pending']);
    }

    #[Test]
    public function intake_manifest_cannot_be_changed_or_deleted_and_denied_users_cannot_read_or_resume_it(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $inputs = ['primary' => ['filename' => 'invoice.xml', 'contents' => $this->ublInvoice()]];
        $store = app(PurchaseInvoiceIntakeStore::class);
        $intake = $store->prepare($entity, $inputs);
        $store->preserve($entity, $intake, $inputs);
        foreach (['change', 'delete', 'forget'] as $operation) {
            try {
                $copy = $intake->fresh();
                match ($operation) {
                    'delete' => $copy->delete(),
                    'forget' => $copy->update(['preserved_files' => []]),
                    default => $copy->update(['files' => []]),
                };
                $this->fail('The intake manifest must be retained.');
            } catch (PostedRecordImmutableException) {
                $this->assertSame($intake->files, $intake->fresh()->files);
            }
        }
        Gate::define(config('filament-accounting.authorization.abilities.register_purchase_invoices'), fn () => false);
        foreach (['read', 'resume'] as $operation) {
            try {
                $operation === 'read' ? $store->read($entity, $intake) : app(ImportPurchaseInvoice::class)->resume($intake);
                $this->fail('Read-only and anonymous users cannot process intakes.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('accounting_documents', 0);
                $this->assertSame('ready', $intake->fresh()->status);
            }
        }
    }

    #[Test]
    public function malformed_xml_and_source_total_mismatches_remain_preserved_without_business_records(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        foreach (['<Invoice>broken', str_replace('119.00', '120.00', $this->ublInvoice())] as $contents) {
            try {
                app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.xml', $contents);
                $this->fail('Unsupported conversion must remain blocked.');
            } catch (DocumentException) {
                $intake = PurchaseInvoiceIntake::query()->latest('id')->firstOrFail();
                $this->assertSame('blocked', $intake->status);
                $this->assertSame($contents, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
                $this->assertDatabaseCount('accounting_documents', 0);
                $this->assertDatabaseCount('accounting_parties', 0);
            }
        }
        $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 2);
        $this->assertSame(2, AuditEvent::query()->where('operation', 'purchase_intake.failed')->count());
    }

    #[Test]
    public function storage_failure_retains_the_intent_and_retry_finishes_the_same_intake(): void
    {
        $disk = Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $fail = true;
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(function (string $path, string $contents, array $options) use ($disk, &$fail): bool {
            if ($fail && str_ends_with($path, '/companion.bin')) {
                return false;
            }

            return $disk->put($path, $contents, $options);
        });
        Storage::shouldReceive('disk')->with('purchase-imports')->andReturn($proxy);
        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
            $this->fail('Storage failure must not be reported as preserved.');
        } catch (AccountingException $exception) {
            $this->assertSame(__('filament-accounting::errors.attachment_write_failed'), $exception->getMessage());
        }
        $intake = PurchaseInvoiceIntake::query()->sole();
        $this->assertNull($intake->preserved_at);
        $this->assertSame('pending', $intake->status);
        $this->assertSame(['primary' => true], $intake->preserved_files);
        $this->assertSame($this->plainPdf(), $disk->get($intake->files['primary']['path']));
        $this->assertDatabaseCount('accounting_documents', 0);
        $fail = false;
        app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
        $this->assertSame('complete', $intake->fresh()->status);
        $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 1);
        $this->assertDatabaseCount('accounting_documents', 1);
    }

    #[Test]
    public function a_write_completed_before_its_metadata_commit_is_recovered_using_the_planned_path(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $inputs = ['primary' => ['filename' => 'invoice.xml', 'contents' => $this->ublInvoice()]];
        $intake = app(PurchaseInvoiceIntakeStore::class)->prepare($entity, $inputs);
        $path = $intake->files['primary']['path'];
        Storage::disk($intake->disk)->put($path, $this->ublInvoice(), ['visibility' => 'private']);
        $this->assertSame([], $intake->preserved_files);

        $result = app(ImportPurchaseInvoice::class)->resume($intake);
        $this->assertSame($path, $result->document->attachments->sole()->path);
        $this->assertSame('complete', $intake->fresh()->status);
        $this->assertSame([$path], Storage::disk($intake->disk)->allFiles());
        $this->assertDatabaseCount('accounting_documents', 1);
        $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 1);
    }

    #[Test]
    public function pending_preservation_accepts_the_same_reupload_but_never_overwrites_damaged_evidence(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $inputs = ['primary' => ['filename' => 'invoice.xml', 'contents' => $this->ublInvoice()]];
        $intake = app(PurchaseInvoiceIntakeStore::class)->prepare($entity, $inputs);
        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'renamed.xml', $this->ublInvoice());
        $this->assertSame($intake->getKey(), PurchaseInvoiceIntake::query()->sole()->getKey());
        $file = $result->document->attachments->sole();
        Storage::disk($intake->disk)->put($file->path, 'damaged');
        try {
            app(ImportPurchaseInvoice::class)->resume($intake);
            $this->fail('Damaged evidence must not be replaced.');
        } catch (AccountingException) {
            $this->assertSame('damaged', Storage::disk($intake->disk)->get($file->path));
            $this->assertSame('integrity_failed', $intake->fresh()->status);
            $this->assertDatabaseCount('accounting_documents', 1);
        }
    }

    #[Test]
    public function intake_rejects_an_enclosing_transaction_before_accepting_files(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        try {
            $entity->getConnection()->transaction(fn () => app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.xml', $this->ublInvoice()));
            $this->fail('An outer rollback must not be able to erase accepted intake evidence.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::errors.intake_requires_independent_commit'), $exception->getMessage());
            $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 0);
            $this->assertSame([], Storage::disk('purchase-imports')->allFiles());
        }
    }

    #[Test]
    public function filament_upload_accepts_xml_and_preserved_invalid_input_remains_visible_without_a_draft(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->makeEntity();
        $this->actingAs($this->makeUser());
        Livewire::test(CreatePurchaseInvoice::class)
            ->fillForm(['original_pdf' => UploadedFile::fake()->createWithContent('invoice.xml', $this->ublInvoice())])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseCount('accounting_documents', 1);

        Livewire::test(CreatePurchaseInvoice::class)
            ->fillForm(['original_pdf' => UploadedFile::fake()->createWithContent('broken.xml', '<Invoice>broken')])
            ->call('create')->assertHasFormErrors(['original_pdf']);
        $blocked = PurchaseInvoiceIntake::query()->where('status', 'blocked')->sole();
        Livewire::test(ListPurchaseInvoiceIntakes::class)->assertOk()->assertSee('broken.xml')
            ->assertSee('Prüfung erforderlich')->assertTableActionHidden('retry', $blocked)
            ->callTableAction('download_primary', $blocked)->assertFileDownloaded('broken.xml');
        $this->assertDatabaseCount('accounting_documents', 1);
        $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 2);
    }

    #[Test]
    public function filament_retries_a_preserved_intake_and_hides_retry_for_integrity_failures(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $inputs = ['primary' => ['filename' => 'invoice.xml', 'contents' => $this->ublInvoice()]];
        $store = app(PurchaseInvoiceIntakeStore::class);
        $intake = $store->prepare($entity, $inputs);
        $store->preserve($entity, $intake, $inputs);
        Livewire::test(ListPurchaseInvoiceIntakes::class)->callTableAction('retry', $intake)
            ->assertHasNoTableActionErrors()->assertRedirect(PurchaseInvoiceResource::getUrl('edit', ['record' => Document::query()->sole()]));
        $intake->refresh();
        Storage::disk($intake->disk)->delete($intake->files['primary']['path']);
        try {
            app(ImportPurchaseInvoice::class)->resume($intake);
        } catch (AccountingException) {
            $intake->refresh();
        }
        Livewire::test(ListPurchaseInvoiceIntakes::class)->assertSee('invoice.xml')->assertTableActionHidden('retry', $intake);
    }

    #[Test]
    public function denied_import_cannot_create_a_supplier_or_return_a_previously_imported_document(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $service = app(ImportPurchaseInvoice::class);
        $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
        Gate::define(config('filament-accounting.authorization.abilities.register_purchase_invoices'), fn () => false);

        foreach ([$this->ublInvoice(), str_replace('Vendor GmbH', 'Other supplier', $this->ublInvoice())] as $xml) {
            try {
                $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $xml);
                $this->fail('Every import attempt requires authorization.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('accounting_documents', 1);
                $this->assertDatabaseCount('accounting_parties', 1);
                $this->assertDatabaseCount('accounting_attachments', 2);
            }
        }
    }

    #[Test]
    public function different_companion_xml_is_not_silently_treated_as_the_same_import(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $service = app(ImportPurchaseInvoice::class);
        $first = $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
        $changedXml = str_replace('INV-42', 'INV-43', $this->ublInvoice());
        $second = $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $changedXml);

        $this->assertNotSame($first->document->getKey(), $second->document->getKey());
        $this->assertFalse($second->idempotentRetry);
        $this->assertSame('INV-43', $second->document->supplier_invoice_number);
        $this->assertSame($changedXml, app(ReadAttachment::class)->handle($second->document->attachments->firstWhere('source_type', 'supplied_e_invoice')));
        $this->assertDatabaseCount('accounting_documents', 2);
        $this->assertDatabaseCount('accounting_attachments', 4);
    }

    #[Test]
    public function failed_xml_metadata_save_preserves_intake_and_retries_without_duplicate_business_records(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $failure = new \RuntimeException('XML metadata unavailable');
        $fail = true;
        Attachment::creating(function (Attachment $attachment) use ($failure, &$fail): void {
            if ($fail && $attachment->source_type === 'supplied_e_invoice') {
                throw $failure;
            }
        });

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
            $this->fail('The XML failure must propagate unchanged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $intake = PurchaseInvoiceIntake::query()->sole();
        $disk = Storage::disk('purchase-imports');
        $this->assertSame($this->plainPdf(), $disk->get($intake->files['primary']['path']));
        $this->assertCount(2, $disk->allFiles());
        $this->assertContains($this->ublInvoice(), array_map(fn (string $path) => $disk->get($path), $disk->allFiles()));
        $this->assertDatabaseCount('accounting_documents', 0);
        $this->assertDatabaseCount('accounting_parties', 0);
        $this->assertDatabaseCount('accounting_attachments', 0);
        $this->assertSame('failed', $intake->status);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
                $this->fail('Unresolved failures must not report success.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
        }
        $this->assertDatabaseCount('accounting_documents', 0);
        $this->assertDatabaseCount('accounting_attachments', 0);
        $this->assertCount(2, $disk->allFiles());
        $fail = false;
        $result = app(ImportPurchaseInvoice::class)->resume($intake);
        $retry = app(ImportPurchaseInvoice::class)->resume($intake);
        $this->assertSame($result->document->getKey(), $retry->document->getKey());
        $this->assertTrue($retry->idempotentRetry);
        $this->assertDatabaseCount('accounting_documents', 1);
        $this->assertDatabaseCount('accounting_parties', 1);
        $this->assertDatabaseCount('accounting_attachments', 2);
        $this->assertDatabaseCount('accounting_purchase_invoice_intakes', 1);
        $this->assertSame('complete', $intake->fresh()->status);
        $this->assertSame(3, AuditEvent::query()->where('operation', 'purchase_intake.failed')->count());
    }

    #[Test]
    public function import_retry_checks_both_originals_and_never_replaces_missing_or_corrupt_bytes(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $service = app(ImportPurchaseInvoice::class);
        $result = $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
        $disk = Storage::disk('purchase-imports');
        foreach ($result->document->attachments as $attachment) {
            $original = $disk->get($attachment->path);
            foreach (['corrupted', null] as $contents) {
                $contents === null ? $disk->delete($attachment->path) : $disk->put($attachment->path, $contents);
                try {
                    $service->handle($entity, 'invoice.pdf', $this->plainPdf(), 'invoice.xml', $this->ublInvoice());
                    $this->fail('Broken originals must block retry.');
                } catch (AccountingException $exception) {
                    $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
                }
                $this->assertSame($contents, $disk->get($attachment->path));
                $this->assertDatabaseCount('accounting_documents', 1);
                $this->assertDatabaseCount('accounting_attachments', 2);
            }
            $disk->put($attachment->path, $original);
        }
    }

    #[Test]
    public function pdf_with_separate_ubl_creates_one_unconfirmed_prefilled_draft_and_retries_idempotently(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);
        PartyTaxId::query()->create(['party_id' => $supplier->getKey(), 'type' => 'vat', 'number' => 'DE999999999', 'country_code' => 'DE']);
        $xml = $this->ublInvoice();
        $pdf = $this->plainPdf();

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.pdf', $pdf, 'vendor-42.xml', $xml);
        $retry = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.pdf', $pdf, 'vendor-42.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('pdf+ubl', $result->format);
        $this->assertSame('matched', $result->supplierMatch);
        $this->assertTrue($retry->idempotentRetry);
        $this->assertSame($result->document->getKey(), $retry->document->getKey());
        $this->assertSame(DocumentStatus::Draft, $result->document->document_status);
        $this->assertSame($supplier->getKey(), $result->document->party_id);
        $this->assertSame('INV-42', $result->document->supplier_invoice_number);
        $this->assertSame(10000, $result->document->net_minor);
        $this->assertSame('DE-19', $result->document->lines->firstOrFail()->tax_code);
        $this->assertFalse($result->document->lines->firstOrFail()->classification_confirmed);
        $this->assertFalse($result->document->lines->firstOrFail()->tax_confirmed);
        $this->assertCount(2, $result->document->attachments);
        $xmlAttachment = $result->document->attachments->firstWhere('source_type', 'supplied_e_invoice');
        $this->assertNotNull($xmlAttachment);
        $this->assertSame($xml, app(ReadAttachment::class)->handle($xmlAttachment));

        $this->expectException(DocumentException::class);
        app(RegisterPurchaseInvoice::class)->receive($result->document);
    }

    #[Test]
    public function pdf_with_separate_ubl_creates_an_unambiguous_missing_supplier(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $result = app(ImportPurchaseInvoice::class)->handle(
            $entity,
            'vendor-42.pdf',
            $this->plainPdf(),
            'vendor-42.xml',
            $this->ublInvoice(),
        );
        $supplier = Party::query()->where('legal_entity_id', $entity->getKey())->where('is_supplier', true)->sole();

        $this->assertSame('created', $result->supplierMatch);
        $this->assertSame($supplier->getKey(), $result->document->party_id);
        $this->assertSame('Vendor GmbH', $supplier->legal_name);
        $this->assertSame('EUR', $supplier->default_currency);
        $this->assertSame('DE999999999', $supplier->taxIds()->sole()->number);
    }

    #[Test]
    public function pdf_with_separate_ubl_keeps_an_ambiguous_supplier_unassigned(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);
        $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);

        $result = app(ImportPurchaseInvoice::class)->handle(
            $entity,
            'vendor-42.pdf',
            $this->plainPdf(),
            'vendor-42.xml',
            $this->ublInvoice(),
        );

        $this->assertSame('ambiguous', $result->supplierMatch);
        $this->assertNull($result->document->party_id);
        $this->assertSame(2, Party::query()->where('legal_entity_id', $entity->getKey())->where('is_supplier', true)->count());
    }

    #[Test]
    public function hybrid_pdf_keeps_the_original_and_extracted_xml_while_plain_pdf_starts_empty(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $salesInvoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [['description' => 'Hybrid source', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'DE-19']],
        ], false);
        $hybridPdf = app(ReadAttachment::class)->handle($salesInvoice->attachments()->where('source_type', 'generated_pdf')->firstOrFail());

        $hybrid = app(ImportPurchaseInvoice::class)->handle($entity, 'hybrid.pdf', $hybridPdf);
        $plain = app(ImportPurchaseInvoice::class)->handle($entity, 'plain.pdf', $this->plainPdf());

        $this->assertTrue($hybrid->structured);
        $this->assertStringStartsWith('hybrid-', $hybrid->format);
        $this->assertCount(2, $hybrid->document->attachments);
        $this->assertFalse($plain->structured);
        $this->assertSame('pdf', $plain->format);
        $this->assertCount(0, $plain->document->lines);
        $this->assertCount(1, $plain->document->attachments);
        $generatedXml = app(ReadAttachment::class)->handle($salesInvoice->attachments()->where('source_type', 'generated_xml')->firstOrFail());
        $standalone = app(ImportPurchaseInvoice::class)->handle($entity, 'standalone.xml', $generatedXml);
        $this->assertSame('zugferd', $standalone->format);
        $this->assertCount(1, $standalone->document->attachments);
        $this->assertSame($generatedXml, app(ReadAttachment::class)->handle($standalone->document->attachments->sole()));

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'conflict.pdf', $hybridPdf, 'other.xml', $this->ublInvoice());
            $this->fail('Conflicting structured originals must not create a draft.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::errors.embedded_xml_mismatch'), $exception->getMessage());
            $intake = PurchaseInvoiceIntake::query()->where('status', 'blocked')->sole();
            $this->assertSame($hybridPdf, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertSame($this->ublInvoice(), Storage::disk($intake->disk)->get($intake->files['companion']['path']));
            $this->assertNull($intake->document_id);
        }
    }

    #[Test]
    public function dangerous_xml_is_preserved_as_inert_bytes_and_never_becomes_a_document(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        try {
            app(ImportPurchaseInvoice::class)->handle(
                $entity,
                'unsafe.pdf',
                $this->plainPdf(),
                'unsafe.xml',
                '<!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Invoice>&xxe;</Invoice>',
            );
            $this->fail('XXE input must be rejected.');
        } catch (DocumentException) {
            $this->assertDatabaseCount('accounting_documents', 0);
            $this->assertDatabaseCount('accounting_attachments', 0);
            $this->assertCount(2, Storage::disk('purchase-imports')->allFiles());
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertNotNull($intake->preserved_at);
            $this->assertStringContainsString('<!DOCTYPE Invoice', Storage::disk($intake->disk)->get($intake->files['companion']['path']));
        }
    }

    #[Test]
    public function standalone_xml_is_preserved_and_creates_one_reviewable_draft(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.xml', $this->ublInvoice());
        $this->assertTrue($result->structured);
        $this->assertSame('ubl', $result->format);
        $this->assertSame(11900, $result->document->gross_minor);
        $this->assertTrue($result->document->e_invoice_meta['validated']);
        $this->assertSame('de_eur_subset_passed', $result->document->e_invoice_meta['validation_status']);
        $this->assertTrue($result->document->e_invoice_meta['extracted']);
        $this->assertCount(1, $result->document->attachments);
        $this->assertSame($this->ublInvoice(), app(ReadAttachment::class)->handle($result->document->attachments->sole()));
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    #[Test]
    public function a_line_level_allowance_net_is_preserved_and_passes_source_total_reconciliation(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        // Quantity 2 × €100 but line net €150 after a €50 line allowance. The
        // parsed line net must win over the recomputed quantity-times-price, or
        // the source-total check rejects a valid invoice.
        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.xml', $this->ublInvoiceWithLineAllowance());
        $document = $result->document;

        $this->assertSame(15000, (int) $document->net_minor);
        $this->assertSame(2850, (int) $document->tax_minor);
        $this->assertSame(17850, (int) $document->gross_minor);
        $line = $document->lines->sole();
        $this->assertSame(15000, (int) $line->net_minor);
    }

    private function plainPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
    }

    private function ublInvoice(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>
  <cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>
  <cbc:ID>INV-42</cbc:ID><cbc:IssueDate>2026-03-10</cbc:IssueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cbc:BuyerReference>BUYER-REF-1</cbc:BuyerReference>
  <cac:AccountingSupplierParty><cac:Party><cbc:EndpointID schemeID="EM">seller@vendor.example</cbc:EndpointID><cac:PartyName><cbc:Name>Vendor GmbH</cbc:Name></cac:PartyName><cac:PostalAddress><cbc:StreetName>Musterweg 1</cbc:StreetName><cbc:CityName>Berlin</cbc:CityName><cbc:PostalZone>10115</cbc:PostalZone><cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country></cac:PostalAddress><cac:PartyTaxScheme><cbc:CompanyID>DE999999999</cbc:CompanyID></cac:PartyTaxScheme><cac:Contact><cbc:Name>Buchhaltung</cbc:Name><cbc:Telephone>+493012345678</cbc:Telephone><cbc:ElectronicMail>seller@vendor.example</cbc:ElectronicMail></cac:Contact></cac:Party></cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty><cac:Party><cbc:EndpointID schemeID="EM">buyer@customer.example</cbc:EndpointID><cac:PartyName><cbc:Name>Buyer GmbH</cbc:Name></cac:PartyName><cac:PostalAddress><cbc:StreetName>Kundenstr. 2</cbc:StreetName><cbc:CityName>München</cbc:CityName><cbc:PostalZone>80331</cbc:PostalZone><cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country></cac:PostalAddress></cac:Party></cac:AccountingCustomerParty>
  <cac:PaymentMeans><cbc:PaymentMeansCode>58</cbc:PaymentMeansCode><cac:PayeeFinancialAccount><cbc:ID>DE89370400440532013000</cbc:ID></cac:PayeeFinancialAccount></cac:PaymentMeans>
  <cac:TaxTotal><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="EUR">100.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>
  <cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">100.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">119.00</cbc:TaxInclusiveAmount></cac:LegalMonetaryTotal>
  <cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="C62">1</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cac:Item><cbc:Name>Hosting</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount currencyID="EUR">100.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>
XML;
    }

    private function ublInvoiceWithLineAllowance(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>
  <cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>
  <cbc:ID>INV-ALLOW</cbc:ID><cbc:IssueDate>2026-03-10</cbc:IssueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cbc:BuyerReference>BUYER-REF-1</cbc:BuyerReference>
  <cac:AccountingSupplierParty><cac:Party><cbc:EndpointID schemeID="EM">seller@vendor.example</cbc:EndpointID><cac:PartyName><cbc:Name>Vendor GmbH</cbc:Name></cac:PartyName><cac:PostalAddress><cbc:StreetName>Musterweg 1</cbc:StreetName><cbc:CityName>Berlin</cbc:CityName><cbc:PostalZone>10115</cbc:PostalZone><cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country></cac:PostalAddress><cac:PartyTaxScheme><cbc:CompanyID>DE999999999</cbc:CompanyID></cac:PartyTaxScheme><cac:Contact><cbc:Name>Buchhaltung</cbc:Name><cbc:Telephone>+493012345678</cbc:Telephone><cbc:ElectronicMail>seller@vendor.example</cbc:ElectronicMail></cac:Contact></cac:Party></cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty><cac:Party><cbc:EndpointID schemeID="EM">buyer@customer.example</cbc:EndpointID><cac:PartyName><cbc:Name>Buyer GmbH</cbc:Name></cac:PartyName><cac:PostalAddress><cbc:StreetName>Kundenstr. 2</cbc:StreetName><cbc:CityName>München</cbc:CityName><cbc:PostalZone>80331</cbc:PostalZone><cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country></cac:PostalAddress></cac:Party></cac:AccountingCustomerParty>
  <cac:PaymentMeans><cbc:PaymentMeansCode>58</cbc:PaymentMeansCode><cac:PayeeFinancialAccount><cbc:ID>DE89370400440532013000</cbc:ID></cac:PayeeFinancialAccount></cac:PaymentMeans>
  <cac:TaxTotal><cbc:TaxAmount currencyID="EUR">28.50</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="EUR">150.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="EUR">28.50</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>
  <cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">150.00</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">150.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">178.50</cbc:TaxInclusiveAmount></cac:LegalMonetaryTotal>
  <cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="C62">2</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">150.00</cbc:LineExtensionAmount><cac:Item><cbc:Name>Hosting with allowance</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount currencyID="EUR">100.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>
XML;
    }
}

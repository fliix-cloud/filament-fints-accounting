<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Filament\Support\AuditExportAction;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class AuditExportRouteTest extends TestCase
{
    // The export refuses to run inside the test-wrapping transaction.
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('route-dataset');
        Storage::fake('route-anchors');
        config()->set('filament-accounting.storage.disk', 'route-dataset');
        config()->set('filament-accounting.audit.anchor.disk', 'route-anchors');
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
    }

    #[Test]
    public function an_unsigned_link_cannot_trigger_the_anchoring_export(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $this->get(route('filament-accounting.audit-export', ['legalEntity' => $entity->getRouteKey()]))
            ->assertForbidden();
    }

    #[Test]
    public function an_expired_signature_is_rejected(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $url = AuditExportAction::url($entity);

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_signed_link_streams_the_export(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $response = $this->get(AuditExportAction::url($entity));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/x-ndjson');
        $this->assertNotSame('', $response->streamedContent());
    }
}

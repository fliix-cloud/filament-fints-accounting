<?php

namespace FilamentAccounting\Tests\Authorization;

use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Filament\Support\AuditExportAction;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class AuditExportRouteTest extends TestCase
{
    #[Test]
    public function guests_cannot_download_the_audit_dataset(): void
    {
        $entity = $this->makeEntity();

        $this->getJson(AuditExportAction::url($entity))
            ->assertUnauthorized();
    }

    #[Test]
    public function authenticated_users_without_export_permission_are_forbidden(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        Gate::define('accounting.audit.export', fn (): bool => false);

        $this->get(AuditExportAction::url($entity))
            ->assertForbidden();
    }

    #[Test]
    public function authorized_users_reach_the_exporter_for_the_current_company(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $this->withoutExceptionHandling();

        try {
            $this->get(AuditExportAction::url($entity));
            $this->fail('Export must not run inside an enclosing accounting transaction.');
        } catch (AuditEvidenceException $exception) {
            $this->assertStringContainsString('independent accounting transaction', $exception->getMessage());
        }
    }
}

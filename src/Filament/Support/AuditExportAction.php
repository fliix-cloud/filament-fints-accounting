<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\URL;

final class AuditExportAction
{
    public static function make(): Action
    {
        return Action::make('exportAudit')
            ->label(__('filament-accounting::actions.export_audit'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (LegalEntity $record): bool => (string) $record->getKey() === (string) app(LegalEntityScope::class)->current()?->getKey()
                && app(AccountingAuthorizer::class)->can('export_audit', $record))
            // The export also writes an audit anchor. A short-lived signature, created
            // on click, keeps other sites from triggering it through a plain link.
            ->action(fn (LegalEntity $record) => redirect()->to(self::url($record)));
    }

    public static function url(LegalEntity $record): string
    {
        return URL::temporarySignedRoute(
            'filament-accounting.audit-export',
            now()->addMinutes(5),
            ['legalEntity' => $record->getRouteKey()],
        );
    }
}

<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Services\InstituteDirectoryService;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;

class ListBankConnections extends ListRecords
{
    protected static string $resource = BankConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInstitutes')
                ->label(__('filament-accounting::banking/fints/actions.sync_institutes'))
                ->visible(fn (): bool => app(BankAuthorizer::class)->can('manage_bank_connections'))
                ->action(function (InstituteDirectoryService $directory): void {
                    app(BankAuthorizer::class)->authorize('manage_bank_connections');
                    FintsUi::notifyInstitutesSynced($directory->sync());
                }),
            CreateAction::make(),
        ];
    }
}

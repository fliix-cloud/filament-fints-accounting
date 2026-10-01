<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankInstitute;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\InstituteDirectoryService;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;

class CreateBankConnection extends CreateRecord
{
    protected static string $resource = BankConnectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['owner_type'], $data['owner_id'], $data['created_by_type'], $data['created_by_id']);
        $data['endpoint_url'] = EndpointValidator::validate((string) ($data['endpoint_url'] ?? ''));
        $data['legal_entity_id'] = app(OwnerScope::class)->require()->getKey();

        $actor = app(BankActorResolver::class)->resolve();
        if ($actor) {
            $data['created_by_type'] = $actor->getMorphClass();
            $data['created_by_id'] = (string) $actor->getKey();
        }

        $candidate = new BankConnection;
        $candidate->forceFill($data);
        app(BankAuthorizer::class)->authorize('manage_bank_connections', $candidate);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInstitutes')
                ->label(__('filament-accounting::banking/fints/actions.sync_institutes'))
                ->visible(fn (): bool => BankInstitute::query()->count() === 0
                    && app(BankAuthorizer::class)->can('manage_bank_connections'))
                ->action(function (InstituteDirectoryService $directory): void {
                    app(BankAuthorizer::class)->authorize('manage_bank_connections');
                    FintsUi::notifyInstitutesSynced($directory->sync());
                }),
        ];
    }
}

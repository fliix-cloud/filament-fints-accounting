<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankSyncRunResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithBankAccountSync;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankSyncRunResource;

class ListBankSyncRuns extends ListRecords
{
    use InteractsWithBankAccountSync;

    protected static string $resource = BankSyncRunResource::class;
}

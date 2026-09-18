<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\EditAccountingBankAccount;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\ListAccountingBankAccounts;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;

class AccountingBankAccountResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = AccountingBankAccount::class;

    protected static ?string $slug = 'accounting/bank-accounts';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANK_SETTINGS;
    }

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.bank_accounts');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.accounting_bank_account.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.accounting_bank_account.plural');
    }

    protected static function ability(): string
    {
        return 'manage_bank_connections';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)
            ->constrain(parent::getEloquentQuery())
            ->where('is_active', true)
            ->where('is_enabled', true)
            ->withCount([
                'statementLines as pending_statement_line_count' => fn (Builder $query): Builder => $query->where('source_status', 'pending'),
            ])
            ->withSum([
                'statementLines as pending_statement_line_sum_minor' => fn (Builder $query): Builder => $query->where('source_status', 'pending'),
            ], 'amount_minor')
            ->orderBy('display_name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name'))->required(),
            TextInput::make('iban')->label(__('filament-accounting::fields.iban'))->disabled(),
            Select::make('currency')->label(__('filament-accounting::fields.currency'))->options(ReferenceData::currencies())->disabled(),
            Toggle::make('is_enabled')
                ->label(__('filament-accounting::fields.bank_account_enabled'))
                ->helperText(__('filament-accounting::fields.bank_account_enabled_help')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label(__('filament-accounting::fields.display_name'))->searchable(),
                TextColumn::make('iban')->label(__('filament-accounting::fields.iban')),
                TextColumn::make('booked_balance_minor')
                    ->label(__('filament-accounting::fields.booked_balance'))
                    ->formatStateUsing(fn (?int $state, AccountingBankAccount $record): ?string => $record->formattedBalance($state)),
                TextColumn::make('pending_statement_line_sum_minor')
                    ->label(__('filament-accounting::fields.pending_balance'))
                    ->placeholder('—')
                    ->formatStateUsing(fn (mixed $state, AccountingBankAccount $record): ?string => (int) $record->pendingStatementLinesSummary()['count'] > 0
                        ? $record->formattedBalance((int) $state)
                        : null),
                TextColumn::make('catch_up_from')
                    ->label(__('filament-accounting::banking/fints/fields.catch_up_from'))
                    ->date()
                    ->placeholder('—')
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('available_amount_minor')
                    ->label(__('filament-accounting::fields.available_amount'))
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state, AccountingBankAccount $record): ?string => $record->formattedBalance($state)),
            ])
            ->recordActions([
                Action::make('continueCatchUp')
                    ->label(__('filament-accounting::banking/fints/actions.continue_catch_up'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->visible(fn (AccountingBankAccount $record): bool => $record->bank_connection_id !== null && $record->catch_up_from !== null)
                    ->disabled(fn (AccountingBankAccount $record): bool => ! ProductRegistration::isConfigured() || ! $record->isUsable())
                    ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                        ? __('filament-accounting::banking/fints/fields.backlog_catch_up_help')
                        : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                    ->action(fn (AccountingBankAccount $record, ListAccountingBankAccounts $livewire) => $livewire->continueCatchUpDrain($record)),
                Action::make('syncTransactions')
                    ->label(__('filament-accounting::banking/fints/actions.sync_transactions'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (AccountingBankAccount $record): bool => $record->bank_connection_id !== null)
                    ->disabled(fn (AccountingBankAccount $record): bool => ! ProductRegistration::isConfigured() || ! $record->isUsable())
                    ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                        ? null
                        : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                    ->action(fn (AccountingBankAccount $record, ListAccountingBankAccounts $livewire) => $livewire->syncBankAccountTransactions($record)),
                Action::make('syncBalance')
                    ->label(__('filament-accounting::banking/fints/actions.sync_balances'))
                    ->icon('heroicon-o-scale')
                    ->visible(fn (AccountingBankAccount $record): bool => $record->bank_connection_id !== null)
                    ->disabled(fn (AccountingBankAccount $record): bool => ! ProductRegistration::isConfigured() || ! $record->isUsable())
                    ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                        ? null
                        : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                    ->action(fn (AccountingBankAccount $record, ListAccountingBankAccounts $livewire) => $livewire->syncBankAccountBalance($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingBankAccounts::route('/'),
            'edit' => EditAccountingBankAccount::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

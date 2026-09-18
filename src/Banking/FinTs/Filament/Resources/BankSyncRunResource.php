<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankSyncRunResource\Pages\ListBankSyncRuns;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class BankSyncRunResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = BankSyncRun::class;

    protected static ?string $slug = 'bank/sync-backlog';

    protected static ?int $navigationSort = 25;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('banking');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::banking/fints/navigation.sync_backlog');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.sync_run.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.sync_run.plural');
    }

    protected static function ability(): string
    {
        return 'manage_bank_connections';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $key = (new BankSyncRun)->getQualifiedKeyName();
        $openKeys = app(BankingBacklogService::class)
            ->openSyncRunsQuery()
            ->select($key);

        return parent::getEloquentQuery()
            ->whereIn($key, $openKeys)
            ->with('account')
            ->where(function (Builder $builder): void {
                try {
                    $entityId = (int) app(LegalEntityScope::class)->require()->getKey();
                    $builder->where('legal_entity_id', $entityId);
                } catch (\Throwable) {
                    $builder->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('id');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->dateTime()
                    ->label(__('filament-accounting::banking/fints/fields.last_sync')),
                TextColumn::make('account.display_name')
                    ->label(__('filament-accounting::banking/fints/fields.account'))
                    ->placeholder('—'),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('requested_from_date')
                    ->date()
                    ->label(__('filament-accounting::banking/fints/fields.catch_up_from'))
                    ->placeholder('—'),
                TextColumn::make('reconciliation_evidence.status')
                    ->label(__('filament-accounting::banking/fints/fields.evidence_status'))
                    ->placeholder('—'),
                TextColumn::make('error_message')
                    ->limit(40)
                    ->label(__('filament-accounting::banking/fints/fields.error_message'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SyncStatus::cases())->mapWithKeys(
                        fn (SyncStatus $status): array => [$status->value => $status->getLabel()]
                    )->all()),
            ])
            ->recordActions([
                Action::make('continueCatchUp')
                    ->label(__('filament-accounting::banking/fints/actions.continue_catch_up'))
                    ->icon('heroicon-o-play')
                    ->visible(fn (BankSyncRun $record): bool => $record->accounting_bank_account_id !== null)
                    ->disabled(fn (BankSyncRun $record): bool => ! ProductRegistration::isConfigured()
                        || ! ($record->account instanceof AccountingBankAccount)
                        || ! $record->account->isUsable()
                        || $record->account->catch_up_from === null)
                    ->action(function (BankSyncRun $record, ListBankSyncRuns $livewire): void {
                        $account = $record->account;
                        if (! $account instanceof AccountingBankAccount) {
                            return;
                        }
                        $livewire->continueCatchUpDrain($account);
                    }),
                Action::make('acknowledge')
                    ->label(__('filament-accounting::banking/fints/actions.acknowledge_backlog'))
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (BankSyncRun $record): bool => ! app(BankingBacklogService::class)->isAcknowledged($record))
                    ->form([
                        Textarea::make('note')
                            ->label(__('filament-accounting::banking/fints/fields.ack_note'))
                            ->rows(2),
                    ])
                    ->action(function (BankSyncRun $record, array $data): void {
                        try {
                            app(BankingBacklogService::class)->acknowledgeSyncRun(
                                $record,
                                $data['note'] ?? null,
                            );
                            Notification::make()
                                ->title(__('filament-accounting::banking/fints/notifications.backlog_acknowledged'))
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankSyncRuns::route('/'),
        ];
    }
}

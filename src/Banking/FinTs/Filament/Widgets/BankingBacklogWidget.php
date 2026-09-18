<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Ownership\LegalEntityScope;

class BankingBacklogWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 5;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            $entityId = (int) app(LegalEntityScope::class)->require()->getKey();
        } catch (\Throwable) {
            return [
                Stat::make(__('filament-accounting::banking/fints/fields.backlog_catch_up'), '—'),
                Stat::make(__('filament-accounting::banking/fints/fields.backlog_sync_runs'), '—'),
                Stat::make(__('filament-accounting::banking/fints/fields.backlog_intakes'), '—'),
                Stat::make(__('filament-accounting::banking/fints/fields.backlog_review'), '—'),
            ];
        }

        $summary = app(BankingBacklogService::class)->summarize($entityId);
        $danger = $summary['has_open_backlog'];

        return [
            Stat::make(
                __('filament-accounting::banking/fints/fields.backlog_catch_up'),
                (string) $summary['catch_up_accounts'],
            )->description(__('filament-accounting::banking/fints/fields.backlog_catch_up_help'))
                ->color($summary['catch_up_accounts'] > 0 ? 'warning' : 'success'),
            Stat::make(
                __('filament-accounting::banking/fints/fields.backlog_sync_runs'),
                (string) $summary['open_sync_runs'],
            )->description(__('filament-accounting::banking/fints/fields.backlog_sync_runs_help'))
                ->color($summary['open_sync_runs'] > 0 ? 'danger' : 'success'),
            Stat::make(
                __('filament-accounting::banking/fints/fields.backlog_intakes'),
                (string) $summary['pending_intakes'],
            )->color($summary['pending_intakes'] > 0 ? 'warning' : 'success'),
            Stat::make(
                __('filament-accounting::banking/fints/fields.backlog_review'),
                (string) $summary['needs_review_lines'],
            )->color($danger ? 'warning' : 'success'),
        ];
    }
}

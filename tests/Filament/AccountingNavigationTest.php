<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SupplierResource;
use FilamentAccounting\Filament\Resources\TaxCodeResource;
use FilamentAccounting\Filament\Widgets\AccountingOverviewStats;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AccountingNavigationTest extends TestCase
{
    #[Test]
    public function workflow_sections_are_registered_as_navigation_groups(): void
    {
        $groups = AccountingNavigation::groups();

        $this->assertSame([
            __('filament-accounting::navigation.sections.banking'),
            __('filament-accounting::navigation.sections.reports'),
            __('filament-accounting::navigation.sections.master_data'),
            __('filament-accounting::navigation.sections.settings'),
        ], array_map(fn ($group): string => $group->getLabel(), $groups));

        foreach ($groups as $group) {
            $this->assertNull($group->getIcon());
        }
    }

    #[Test]
    public function invoices_are_top_level_and_workflow_resources_use_the_requested_groups(): void
    {
        $expected = [
            AccountingNavigation::section('banking') => [
                BankStatementLineResource::class,
                BankTransferResource::class,
                BankDirectDebitResource::class,
            ],
            AccountingNavigation::section('reports') => [
                JournalEntryResource::class,
            ],
            AccountingNavigation::section('master_data') => [
                CustomerResource::class,
                SupplierResource::class,
                CatalogItemResource::class,
            ],
            AccountingNavigation::section('settings') => [
                TaxCodeResource::class,
                LegalEntityResource::class,
            ],
        ];

        foreach ([SalesInvoiceResource::class, PurchaseInvoiceResource::class] as $resource) {
            $this->assertNull($resource::getNavigationGroup());
            $this->assertNull($resource::getNavigationParentItem());
        }

        foreach ($expected as $group => $resources) {
            foreach ($resources as $resource) {
                $this->assertSame($group, $resource::getNavigationGroup());
                $this->assertNull($resource::getNavigationParentItem());
            }
        }
    }

    #[Test]
    public function bank_configuration_is_nested_below_bank_in_settings(): void
    {
        $bankParent = AccountingNavigation::items()[0];

        $this->assertSame(AccountingNavigation::BANK_SETTINGS, $bankParent->getKey());
        $this->assertSame(AccountingNavigation::section('settings'), $bankParent->getGroup());
        $this->assertSame(__('filament-accounting::navigation.bank_settings'), $bankParent->getLabel());
        $this->assertNull($bankParent->getUrl());

        foreach ([
            BankConnectionResource::class,
            AccountingBankAccountResource::class,
            DirectDebitCreditorProfileResource::class,
            DirectDebitMandateResource::class,
            ReconciliationLearningRuleResource::class,
        ] as $resource) {
            $this->assertSame(AccountingNavigation::section('settings'), $resource::getNavigationGroup());
            $this->assertSame(AccountingNavigation::BANK_SETTINGS, $resource::getNavigationParentItem());
        }

        $this->assertFalse(ReconciliationPage::shouldRegisterNavigation());
    }

    #[Test]
    public function filament_builds_the_requested_sidebar_tree(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->makeUser());
        $this->makeEntity();

        $this->assertContains(AccountingOverviewStats::class, filament()->getPanel('admin')->getWidgets());
        $this->assertNotContains(
            'FilamentAccounting\\Banking\\FinTs\\Filament\\Widgets\\PendingScaWidget',
            filament()->getPanel('admin')->getWidgets(),
        );

        $groups = collect(filament()->getNavigation())->keyBy(
            fn ($group): string => $group->getLabel() ?? 'top',
        );

        $this->assertSame(['top', 'Bank & Zuordnung', 'Auswertungen', 'Stammdaten', 'Einstellungen'], $groups->keys()->all());
        $this->assertSame(
            ['Ausgangsrechnungen', 'Eingangsrechnungen'],
            collect($groups->get('top')?->getItems())->map(fn ($item): string => $item->getLabel())->take(-2)->values()->all(),
        );
        $this->assertSame(
            ['Sync-Backlog', 'Umsätze', 'Überweisungen', 'Lastschriften'],
            collect($groups->get('Bank & Zuordnung')?->getItems())->map(fn ($item): string => $item->getLabel())->values()->all(),
        );
        $this->assertSame(
            ['Kunden', 'Lieferanten', 'Produkte & Leistungen'],
            collect($groups->get('Stammdaten')?->getItems())->map(fn ($item): string => $item->getLabel())->values()->all(),
        );
        $this->assertSame(
            ['Journal'],
            collect($groups->get('Auswertungen')?->getItems())->map(fn ($item): string => $item->getLabel())->values()->all(),
        );

        $settings = collect($groups->get('Einstellungen')?->getItems())->keyBy(
            fn ($item): string => $item->getLabel(),
        );

        $this->assertSame(['Bank', 'Steuersätze', 'Unternehmensdaten', 'Steuerfälle'], $settings->keys()->all());
        $this->assertSame(
            ['Bankverbindungen', 'Bankkonten', 'Gläubiger', 'Mandate', 'Lernregeln'],
            collect($settings->get('Bank')?->getChildItems())->map(fn ($item): string => $item->getLabel())->values()->all(),
        );
    }

    #[Test]
    public function all_fints_management_resources_register_navigation(): void
    {
        foreach ([
            BankConnectionResource::class,
            BankTransferResource::class,
            DirectDebitCreditorProfileResource::class,
            DirectDebitMandateResource::class,
            BankDirectDebitResource::class,
        ] as $resource) {
            $this->assertTrue($resource::shouldRegisterNavigation());
        }
    }
}

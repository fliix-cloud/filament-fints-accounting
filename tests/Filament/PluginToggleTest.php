<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use FilamentAccounting\Banking\FinTs\Filament\Pages\StrongAuthentication;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankSyncRunResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Banking\FinTs\Filament\Widgets\BankBalancesWidget;
use FilamentAccounting\Banking\FinTs\Filament\Widgets\BankingBacklogWidget;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Filament\Resources\AuditEventResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SupplierResource;
use FilamentAccounting\Filament\Resources\TaxCodeResource;
use FilamentAccounting\Filament\Widgets\AccountingOverviewStats;
use FilamentAccounting\FilamentAccountingPlugin;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class PluginToggleTest extends TestCase
{
    #[Test]
    public function every_feature_controls_only_its_registered_components(): void
    {
        $features = [
            'dashboard' => [AccountingOverviewStats::class],
            'customers' => [CustomerResource::class],
            'suppliers' => [SupplierResource::class],
            'catalog' => [CatalogItemResource::class],
            'sales_invoices' => [SalesInvoiceResource::class],
            'purchase_invoices' => [PurchaseInvoiceResource::class],
            'bank_reconciliation' => [AccountingBankAccountResource::class, BankSyncRunResource::class, BankStatementLineResource::class, BankTransferResource::class, BankDirectDebitResource::class, ReconciliationLearningRuleResource::class, ReconciliationPage::class, StrongAuthentication::class, BankBalancesWidget::class, BankingBacklogWidget::class],
            'journal' => [JournalEntryResource::class],
            'chart_of_accounts' => [LedgerAccountResource::class],
            'tax_and_posting_rules' => [TaxCodeResource::class, PostingRuleResource::class],
            'settings' => [LegalEntityResource::class, BankConnectionResource::class, DirectDebitCreditorProfileResource::class, DirectDebitMandateResource::class],
            'audit' => [AuditEventResource::class],
        ];
        $this->assertEqualsCanonicalizing(array_keys($features), array_keys(config('filament-accounting.features')));
        $all = array_merge(...array_values($features));

        foreach ($features as $feature => $components) {
            foreach (['config', 'fluent'] as $mode) {
                config()->set('filament-accounting.features', array_fill_keys(array_keys($features), true));
                $plugin = FilamentAccountingPlugin::make();
                $method = Str::camel($feature);

                if ($mode === 'config') {
                    config()->set("filament-accounting.features.{$feature}", false);
                    $plugin->{$method}(true); // Fluent enable must not bypass a config disable.
                } else {
                    $plugin->{$method}(false);
                }

                $panel = Panel::make()->id("test-{$feature}-{$mode}");
                $plugin->register($panel);

                $this->assertEqualsCanonicalizing(
                    array_values(array_diff($all, $components)),
                    array_merge($panel->getResources(), $panel->getPages(), $panel->getWidgets()),
                    "Feature {$feature} via {$mode}",
                );
            }
        }
    }

    #[Test]
    public function defaults_keep_chart_and_audit_opt_in_and_register_posting_rules(): void
    {
        $panel = Panel::make()->id('defaults');
        FilamentAccountingPlugin::make()->register($panel);

        $this->assertNotContains(LedgerAccountResource::class, $panel->getResources());
        $this->assertNotContains(AuditEventResource::class, $panel->getResources());
        $this->assertContains(PostingRuleResource::class, $panel->getResources());
        $this->assertContains(TaxCodeResource::class, $panel->getResources());
    }

    #[Test]
    public function disabling_all_features_leaves_no_components_or_bank_parent(): void
    {
        config()->set('filament-accounting.features', array_fill_keys(array_keys(config('filament-accounting.features')), false));
        $panel = Panel::make()->id('disabled');
        FilamentAccountingPlugin::make()->register($panel);

        $this->assertSame([], $panel->getResources());
        $this->assertSame([], $panel->getPages());
        $this->assertSame([], $panel->getWidgets());
        $this->assertSame([], $panel->getNavigationItems());
    }

    #[Test]
    public function host_width_and_standard_colors_are_preserved_and_full_width_is_opt_in(): void
    {
        $panel = Panel::make()->id('host')->maxContentWidth(Width::Large)->colors(['primary' => Color::Purple]);
        FilamentAccountingPlugin::make()->register($panel);

        $this->assertSame(Width::Large, $panel->getMaxContentWidth());
        $this->assertSame(Color::Purple, $panel->getColors()['primary']);
        $this->assertArrayHasKey('accounting-negative', $panel->getColors());
        $this->assertArrayHasKey('accounting-positive', $panel->getColors());

        FilamentAccountingPlugin::make()->fullWidth()->register($panel);
        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
    }
}

<?php

namespace FilamentAccounting;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use FilamentAccounting\Banking\FinTs\Filament\Pages\StrongAuthentication;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankSyncRunResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Banking\FinTs\Filament\Widgets\BankBalancesWidget;
use FilamentAccounting\Banking\FinTs\Filament\Widgets\BankingBacklogWidget;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
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

class FilamentAccountingPlugin implements Plugin
{
    protected bool $hasDashboard = true;

    protected bool $hasCustomers = true;

    protected bool $hasSuppliers = true;

    protected bool $hasCatalog = true;

    protected bool $hasSalesInvoices = true;

    protected bool $hasPurchaseInvoices = true;

    protected bool $hasBankReconciliation = true;

    protected bool $hasJournal = true;

    protected bool $hasSettings = true;

    protected bool $hasChartOfAccounts = true;

    protected bool $hasTaxAndPostingRules = true;

    protected bool $hasAudit = true;

    protected bool $usesFullWidth = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        $plugin = filament(app(static::class)->getId());

        if (! $plugin instanceof static) {
            throw new \RuntimeException('The filament-accounting plugin is not registered on the current panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-accounting';
    }

    public function customers(bool $condition = true): static
    {
        $this->hasCustomers = $condition;

        return $this;
    }

    public function suppliers(bool $condition = true): static
    {
        $this->hasSuppliers = $condition;

        return $this;
    }

    public function catalog(bool $condition = true): static
    {
        $this->hasCatalog = $condition;

        return $this;
    }

    public function salesInvoices(bool $condition = true): static
    {
        $this->hasSalesInvoices = $condition;

        return $this;
    }

    public function purchaseInvoices(bool $condition = true): static
    {
        $this->hasPurchaseInvoices = $condition;

        return $this;
    }

    public function bankReconciliation(bool $condition = true): static
    {
        $this->hasBankReconciliation = $condition;

        return $this;
    }

    public function journal(bool $condition = true): static
    {
        $this->hasJournal = $condition;

        return $this;
    }

    public function dashboard(bool $condition = true): static
    {
        $this->hasDashboard = $condition;

        return $this;
    }

    public function settings(bool $condition = true): static
    {
        $this->hasSettings = $condition;

        return $this;
    }

    public function hasCustomers(): bool
    {
        return $this->hasCustomers && $this->enabled('customers');
    }

    public function chartOfAccounts(bool $condition = true): static
    {
        $this->hasChartOfAccounts = $condition;

        return $this;
    }

    public function taxAndPostingRules(bool $condition = true): static
    {
        $this->hasTaxAndPostingRules = $condition;

        return $this;
    }

    public function audit(bool $condition = true): static
    {
        $this->hasAudit = $condition;

        return $this;
    }

    public function fullWidth(bool $condition = true): static
    {
        $this->usesFullWidth = $condition;

        return $this;
    }

    public function hasSuppliers(): bool
    {
        return $this->hasSuppliers && $this->enabled('suppliers');
    }

    public function hasJournal(): bool
    {
        return $this->hasJournal && $this->enabled('journal');
    }

    public function register(Panel $panel): void
    {
        $pages = [];
        $resources = [];
        $widgets = [];

        if ($this->enabled('dashboard') && $this->hasDashboard) {
            array_unshift($widgets, AccountingOverviewStats::class);
        }

        if ($this->enabled('customers') && $this->hasCustomers) {
            $resources[] = CustomerResource::class;
        }

        if ($this->enabled('suppliers') && $this->hasSuppliers) {
            $resources[] = SupplierResource::class;
        }

        if ($this->enabled('catalog') && $this->hasCatalog) {
            $resources[] = CatalogItemResource::class;
        }

        if ($this->enabled('sales_invoices') && $this->hasSalesInvoices) {
            $resources[] = SalesInvoiceResource::class;
        }

        if ($this->enabled('purchase_invoices') && $this->hasPurchaseInvoices) {
            $resources[] = PurchaseInvoiceResource::class;
        }

        if ($this->enabled('bank_reconciliation') && $this->hasBankReconciliation) {
            $widgets[] = BankBalancesWidget::class;
            $widgets[] = BankingBacklogWidget::class;
            $resources[] = AccountingBankAccountResource::class;
            $resources[] = BankSyncRunResource::class;
            $resources[] = BankStatementLineResource::class;
            $resources[] = BankTransferResource::class;
            $resources[] = BankDirectDebitResource::class;
            $resources[] = ReconciliationLearningRuleResource::class;
            $pages[] = ReconciliationPage::class;
            $pages[] = StrongAuthentication::class;
        }

        if ($this->enabled('journal') && $this->hasJournal) {
            $resources[] = JournalEntryResource::class;
        }

        if ($this->enabled('chart_of_accounts') && $this->hasChartOfAccounts) {
            $resources[] = LedgerAccountResource::class;
        }

        if ($this->enabled('tax_and_posting_rules') && $this->hasTaxAndPostingRules) {
            $resources[] = TaxCodeResource::class;
            $resources[] = PostingRuleResource::class;
        }

        if ($this->enabled('audit') && $this->hasAudit) {
            $resources[] = AuditEventResource::class;
        }

        if ($this->enabled('settings') && $this->hasSettings) {
            $resources[] = LegalEntityResource::class;
            $resources[] = BankConnectionResource::class;
            $resources[] = DirectDebitCreditorProfileResource::class;
            $resources[] = DirectDebitMandateResource::class;
        }

        if ($this->usesFullWidth) {
            $panel->maxContentWidth(Width::Full);
        }

        if (in_array(AccountingBankAccountResource::class, $resources, true)
            || in_array(BankConnectionResource::class, $resources, true)) {
            $panel->navigationItems(AccountingNavigation::items());
        }

        $panel
            ->colors([
                'accounting-negative' => Color::hex('#0072B2'),
                'accounting-positive' => Color::hex('#009E73'),
            ])
            ->navigationGroups(AccountingNavigation::groups())
            ->pages($pages)
            ->resources($resources)
            ->widgets($widgets);
    }

    public function boot(Panel $panel): void {}

    protected function enabled(string $feature): bool
    {
        return (bool) config("filament-accounting.features.{$feature}", true);
    }
}

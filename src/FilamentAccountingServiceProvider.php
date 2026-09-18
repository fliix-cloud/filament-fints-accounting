<?php

namespace FilamentAccounting;

use FilamentAccounting\Audit\FilesystemAuditAnchorStore;
use FilamentAccounting\Banking\FinTs\Commands\BacklogCommand;
use FilamentAccounting\Banking\FinTs\Commands\CleanupScaCommand;
use FilamentAccounting\Banking\FinTs\Commands\SyncCommand;
use FilamentAccounting\Banking\FinTs\Commands\SyncInstitutesCommand;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope;
use FilamentAccounting\Banking\FinTs\Policies\BankConnectionPolicy;
use FilamentAccounting\Banking\FinTs\Services\PhpFintsClientFactory;
use FilamentAccounting\Commands\CreateAuditAnchorCommand;
use FilamentAccounting\Commands\ExportAuditEvidenceCommand;
use FilamentAccounting\Commands\InstallCommand;
use FilamentAccounting\Commands\SeedProfileCommand;
use FilamentAccounting\Commands\StorageIntegrityCommand;
use FilamentAccounting\Commands\VerifyAuditEvidenceCommand;
use FilamentAccounting\Commands\VerifyCommand;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Contracts\AccountingExporter;
use FilamentAccounting\Contracts\AccountingTenancyContextActivator;
use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Contracts\ReconciliationMatcher;
use FilamentAccounting\Documents\BladeInvoiceRenderer;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Export\GenericJournalCsvExporter;
use FilamentAccounting\Ledger\FirstPartyLedgerEngine;
use FilamentAccounting\Livewire\ReconciliationAssistant;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Reconciliation\DeterministicReconciliationMatcher;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAccountingServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-accounting';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-accounting')
            ->hasCommands([
                CreateAuditAnchorCommand::class,
                BacklogCommand::class,
                CleanupScaCommand::class,
                ExportAuditEvidenceCommand::class,
                InstallCommand::class,
                SeedProfileCommand::class,
                StorageIntegrityCommand::class,
                SyncCommand::class,
                SyncInstitutesCommand::class,
                VerifyAuditEvidenceCommand::class,
                VerifyCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-accounting.php', 'filament-accounting');
        $this->publishes([
            __DIR__.'/../config/filament-accounting.php' => config_path('filament-accounting.php'),
        ], 'filament-accounting-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/fints.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/accounting.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-accounting');

        $this->app->singleton(config('filament-accounting.ownership.entity_resolver'));
        $this->app->singleton(AccountingEntityResolver::class, function ($app) {
            return $app->make(config('filament-accounting.ownership.entity_resolver'));
        });
        $this->app->singleton(AccountingActorResolver::class, function ($app) {
            return $app->make(config('filament-accounting.actor.resolver'));
        });
        $this->app->singleton(AccountingTenancyContextActivator::class, function ($app) {
            return $app->make(config('filament-accounting.tenancy.context_activator'));
        });
        $this->app->singleton(AccountingAuthorizer::class, function ($app) {
            return $app->make(config('filament-accounting.authorization.authorizer'));
        });
        $this->app->singleton(LegalEntityScope::class);
        $this->app->singleton(LegalEntityBankScope::class);
        $this->app->singleton(FintsClientFactory::class, PhpFintsClientFactory::class);
        $this->app->singleton(LedgerEngine::class, FirstPartyLedgerEngine::class);
        $this->app->singleton(ReconciliationMatcher::class, DeterministicReconciliationMatcher::class);
        $this->app->singleton(EInvoiceAdapter::class, ZugferdEInvoiceAdapter::class);
        $this->app->singleton(InvoiceRenderer::class, BladeInvoiceRenderer::class);
        $this->app->singleton(AccountingExporter::class, GenericJournalCsvExporter::class);
        $this->app->singleton(AuditAnchorStore::class, function ($app) {
            return $app->make(config('filament-accounting.audit.anchor.store', FilesystemAuditAnchorStore::class));
        });
    }

    protected function registerPackageTranslations(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'lang';

        $this->loadTranslationsFrom($path, 'filament-accounting');
        $this->loadJsonTranslationsFrom($path);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $path => function_exists('lang_path')
                    ? lang_path('vendor/filament-accounting')
                    : resource_path('lang/vendor/filament-accounting'),
            ], 'filament-accounting-translations');
        }
    }

    protected function getPackageBaseDir(): string
    {
        return dirname(__DIR__).'/src';
    }

    public function packageBooted(): void
    {
        $this->registerPackageTranslations();

        Gate::policy(BankConnection::class, BankConnectionPolicy::class);

        if ($this->app->bound('livewire.finder')) {
            Livewire::component('filament-accounting.reconciliation-assistant', ReconciliationAssistant::class);
        }
    }
}

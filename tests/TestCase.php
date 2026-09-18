<?php

namespace FilamentAccounting\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use FilamentAccounting\Enums\LegalEntityState;
use FilamentAccounting\FilamentAccountingServiceProvider;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Services\SeedGermanProfile;
use FilamentAccounting\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\Filament\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.providers.users.model', User::class);
        config()->set('filament-accounting.ownership.required', true);
        config()->set('filament-accounting.storage.disk', 'local');
        config()->set('filament-accounting.e_invoice.generate_on_issue', false);
        $this->app->instance(DataStore::class, new DataStore);
        $this->defineAccountingGates();
    }

    protected function defineAccountingGates(): void
    {
        // Explicit full-access fixture permissions; authorization tests override this setup.
        foreach (config('filament-accounting.authorization.abilities') as $ability) {
            Gate::define($ability, fn ($user = null): bool => true);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            ActionsServiceProvider::class,
            InfolistsServiceProvider::class,
            WidgetsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentAccountingServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadPackageAndFixtureMigrations();
    }

    protected function afterRefreshingDatabase(): void
    {
        $this->loadPackageAndFixtureMigrations();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.key', 'base64:2fl+Ktv0d8C2y+58p0rY/Jnp4inNZ4gKvsbSFn3g6mI=');
    }

    protected function makeUser(string $email = 'user@example.com'): User
    {
        return User::query()->create([
            'email' => $email,
            'name' => 'User',
            'password' => 'password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeEntity(array $overrides = []): LegalEntity
    {
        $entity = LegalEntity::query()->create(array_merge([
            'legal_name' => 'Demo GmbH',
            'country_code' => 'DE',
            'base_currency' => 'EUR',
            'locale' => 'de_DE',
            'timezone' => 'Europe/Berlin',
            'fiscal_year_start_month' => 1,
            'compliance_profile_key' => 'DE',
            'state' => LegalEntityState::Active,
        ], $overrides));

        $resolver = app(SingleLegalEntityResolver::class);
        $resolver->bind($entity);

        app(SeedGermanProfile::class)->handle($entity);

        return $entity->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeParty(LegalEntity $entity, array $overrides = []): Party
    {
        return Party::query()->create(array_merge([
            'legal_entity_id' => $entity->getKey(),
            'kind' => 'organization',
            'is_customer' => true,
            'is_supplier' => false,
            'legal_name' => 'Acme GmbH',
            'country_code' => 'DE',
            'payment_terms_days' => 14,
            'default_currency' => $entity->base_currency,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeBankAccount(LegalEntity $entity, ?int $ledgerAccountId = null): AccountingBankAccount
    {
        $ledgerAccountId ??= $entity->ledgerAccounts()->where('code', '1200')->value('id');

        return AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Giro',
            'iban' => 'DE89370400440532013000',
            'currency' => $entity->base_currency,
            'ledger_account_id' => $ledgerAccountId,
            'source' => 'synthetic',
            'external_account_id' => 'acc-1',
            'is_active' => true,
        ]);
    }

    private function loadPackageAndFixtureMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        }

        if (! Schema::hasTable('accounting_legal_entities')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}

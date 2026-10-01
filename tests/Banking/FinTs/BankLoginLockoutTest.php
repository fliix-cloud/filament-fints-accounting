<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Exceptions\AuthenticationException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BankConnectionService;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClient;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClientFactory;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Banks lock the PIN after a few rejected logins. A rejected login must stop
 * every automatic retry until an operator tests the connection again.
 */
class BankLoginLockoutTest extends TestCase
{
    #[Test]
    public function a_rejected_login_blocks_further_automatic_logins(): void
    {
        $this->actingAs($this->makeUser());
        $connection = $this->connection($this->makeEntity());
        $client = new FakeFintsClient;
        $client->loginFailure = new AuthenticationException('PIN falsch');
        $this->app->instance(FintsClientFactory::class, new FakeFintsClientFactory($client));

        try {
            app(AccountSyncService::class)->sync($connection);
            $this->fail('Expected the rejected login to surface.');
        } catch (AuthenticationException) {
            // Expected.
        }

        $connection->refresh();
        $this->assertSame(BankConnectionStatus::Error, $connection->status);
        $this->assertSame(BankConnection::AUTHENTICATION_FAILED, $connection->last_error_code);
        $this->assertTrue($connection->blocksAutomaticLogin());

        try {
            app(AccountSyncService::class)->sync($connection);
            $this->fail('Expected the blocked connection to refuse a login.');
        } catch (AuthenticationException $e) {
            $this->assertSame(__('filament-accounting::banking/fints/errors.authentication_blocked'), $e->getMessage());
        }

        $this->assertSame(1, $client->loginCalls, 'The bank must not see a second login attempt.');
    }

    #[Test]
    public function a_successful_connection_test_lifts_the_block(): void
    {
        $this->actingAs($this->makeUser());
        $connection = $this->connection($this->makeEntity());
        $connection->markAuthenticationFailed('PIN falsch');
        $this->assertTrue($connection->blocksAutomaticLogin());

        $connection = app(BankConnectionService::class)->markSuccessful($connection, new FakeFintsClient);

        $this->assertSame(BankConnectionStatus::Active, $connection->status);
        $this->assertNull($connection->last_error_code);
        $this->assertFalse($connection->blocksAutomaticLogin());
    }

    #[Test]
    public function a_disabled_connection_never_logs_in(): void
    {
        $this->actingAs($this->makeUser());
        $connection = $this->connection($this->makeEntity());
        $connection->status = BankConnectionStatus::Disabled;
        $connection->save();
        $client = new FakeFintsClient;
        $this->app->instance(FintsClientFactory::class, new FakeFintsClientFactory($client));

        try {
            app(AccountSyncService::class)->sync($connection);
            $this->fail('Expected the disabled connection to refuse a login.');
        } catch (AuthenticationException) {
            // Expected.
        }

        $this->assertSame(0, $client->loginCalls);
    }

    #[Test]
    public function the_sync_command_skips_blocked_connections_and_isolates_failures(): void
    {
        config()->set('filament-accounting.banking.fints.product.id', 'TEST-PRODUCT-ID');
        $this->actingAs($this->makeUser());
        $entity = $this->makeEntity();
        $blocked = $this->connection($entity, 'Gesperrt');
        $blocked->markAuthenticationFailed('PIN falsch');
        $broken = $this->connection($entity, 'Kaputt');
        $healthy = $this->connection($entity, 'Gesund');

        $client = new FakeFintsClient;
        $this->app->instance(FintsClientFactory::class, new class($client, $broken->uuid) implements FintsClientFactory
        {
            public function __construct(
                private readonly FakeFintsClient $client,
                private readonly string $brokenUuid,
            ) {}

            public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient
            {
                if ($connection->uuid === $this->brokenUuid) {
                    throw new \RuntimeException('bank unreachable');
                }

                return $this->client;
            }
        });

        $this->artisan('filament-accounting:sync-bank', ['--accounts' => true])
            ->expectsOutputToContain("Connection {$blocked->uuid} skipped")
            ->expectsOutputToContain("Connection {$broken->uuid} failed")
            ->assertFailed();

        $this->assertSame(1, $client->loginCalls, 'Only the healthy connection may contact the bank.');
        $this->assertFalse(BankSyncRun::query()->where('bank_connection_id', $blocked->id)->exists());
        // The connection after the broken one was still attempted.
        $this->assertTrue(BankSyncRun::query()
            ->where('bank_connection_id', $healthy->id)
            ->where('type', SyncType::Accounts)
            ->exists());
    }

    private function connection(LegalEntity $entity, string $name = 'Testbank'): BankConnection
    {
        return BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => $name,
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
        ]);
    }
}

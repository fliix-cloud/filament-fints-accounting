<?php

namespace FilamentAccounting\Tests\Integration;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Exceptions\ConcurrentBankSyncException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Events\ReconciliationFinalized;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Export\AccountingDatasetExporter;
use FilamentAccounting\Export\DatasetInspection;
use FilamentAccounting\Export\DatasetSnapshot;
use FilamentAccounting\Export\StreamDatasetExporter;
use FilamentAccounting\Export\StreamDatasetVerifier;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReverseReconciliation;
use FilamentAccounting\Tests\Fixtures\User;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/** Opt-in: creates and drops only its own randomly named, disposable databases. */
class MySqlConcurrencyTest extends TestCase
{
    private ?PDO $admin = null;

    private string $database = '';

    private bool $ownsDatabase = false;

    private array $job = [];

    protected function setUp(): void
    {
        if (getenv('ACCOUNTING_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Set ACCOUNTING_TEST_MYSQL=1 to run isolated MySQL process tests.');
        }
        $this->job = json_decode(getenv('ACCOUNTING_TEST_MYSQL_JOB') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        if ($this->name() === 'worker' && $this->job === []) {
            $this->markTestSkipped('Worker is invoked by a parent concurrency test.');
        }
        $this->database = $this->job['database'] ?? 'acct_concurrency_'.bin2hex(random_bytes(12));
        if (! preg_match('/^acct_concurrency_[a-f0-9]{24}$/D', $this->database)) {
            throw new \RuntimeException('Invalid isolated test database name.');
        }
        if ($this->job === []) {
            $this->admin = new PDO('mysql:host='.(getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1')
                .';port='.(getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306'),
                getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->ownsDatabase = true;
        }
        parent::setUp();
        if ($this->job === []) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.mysql_concurrency', [
            'driver' => 'mysql', 'host' => getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306', 'database' => $this->database,
            'username' => getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('database.default', 'mysql_concurrency');
        $app['config']->set('filament-accounting.database.connection', 'mysql_concurrency');
    }

    protected function refreshTestDatabase(): void {}

    protected function defineDatabaseMigrations(): void
    {
        if ($this->job === []) {
            parent::defineDatabaseMigrations();
        }
    }

    protected function afterRefreshingDatabase(): void {}

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->ownsDatabase && $this->admin !== null) {
                $this->admin->exec('DROP DATABASE `'.$this->database.'`');
                $this->ownsDatabase = false;
            }
        }
    }

    public static function scenarios(): array
    {
        return [
            'duplicate' => ['duplicate'],
            'competing_payments' => ['competing_payments'],
            'payment_first' => ['payment_first'],
            'correction_first' => ['correction_first'],
        ];
    }

    public static function exportFormats(): array
    {
        return ['json' => [false], 'stream' => [true]];
    }

    #[Test]
    #[DataProvider('exportFormats')]
    public function export_reads_one_snapshot_while_independent_master_data_changes_commit(bool $streaming): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $party = $this->makeParty($entity);
        $connection = $entity->getConnection();
        $connection->table('accounting_parties')->where('id', $party->id)->update(['display_name' => 'Before snapshot']);
        $catalog = $connection->table('accounting_catalog_items')->insertGetId([
            'legal_entity_id' => $entity->id, 'uuid' => (string) Str::uuid(),
            'sku' => 'SNAPSHOT', 'name' => 'Before snapshot', 'type' => 'service',
            'unit' => 'piece', 'currency' => 'EUR', 'default_unit_price_minor' => 10000,
        ]);
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $ipc = tempnam(sys_get_temp_dir(), 'acct-export-');
        $changed = false;
        $listening = true;
        $process = null;
        $connection->listen(function ($query) use ($entity, $user, $party, $catalog, $ipc, &$changed, &$listening, &$process): void {
            if (! $listening || $changed || ! str_contains($query->sql, 'from `accounting_parties`')
                || ! str_contains($query->sql, 'order by `id`')) {
                return;
            }
            $changed = true;
            $process = $this->bookingProcess(['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
                'operation' => 'update_export_inputs', 'entity' => $entity->id, 'party' => $party->id, 'catalog' => $catalog]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        });
        try {
            if ($streaming) {
                $export = app(StreamDatasetExporter::class)->build($entity);
                try {
                    $this->assertTrue($export['report']['valid']);
                    app(StreamDatasetVerifier::class)->verify($export['stream'], function (DatasetInspection $index): void {
                        $this->assertSame('Before snapshot', $index->database->query('SELECT display_name FROM accounting_parties')->fetchColumn());
                        $this->assertSame('Before snapshot', $index->database->query('SELECT name FROM accounting_catalog_items WHERE sku=\'SNAPSHOT\'')->fetchColumn());
                    });
                } finally {
                    fclose($export['stream']);
                }
            } else {
                $export = app(AccountingDatasetExporter::class)->build($entity);
                $this->assertSame('Before snapshot', $export['dataset']['records']['accounting_parties'][0]['display_name']);
                $this->assertSame('Before snapshot', $export['dataset']['records']['accounting_catalog_items'][0]['name']);
            }
            $this->assertTrue($changed, 'The writer must commit between export table reads.');
            $this->assertSame('After snapshot', $party->fresh()->display_name);
            $this->assertSame('After snapshot', $connection->table('accounting_catalog_items')->where('id', $catalog)->value('name'));
            $this->assertSame('READ-COMMITTED', $connection->selectOne('SELECT @@session.transaction_isolation AS isolation_level')->isolation_level);
            $this->assertSame(0, $connection->transactionLevel());
        } finally {
            $listening = false;
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            unlink($ipc);
        }
    }

    #[Test]
    #[DataProvider('exportFormats')]
    public function export_serializes_with_payment_and_the_next_export_contains_the_committed_payment(bool $streaming): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('export-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->sole();
        $ipc = tempnam(sys_get_temp_dir(), 'acct-export-payment-');
        $process = $this->bookingProcess(['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
            'operation' => 'assign', 'line' => $line->id, 'item' => $invoice->openItem->id]);
        $started = false;
        $listening = true;
        $connection = $entity->getConnection();
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $connection->listen(function ($query) use ($process, $ipc, &$started, &$listening): void {
            if (! $listening || $started || ! str_contains($query->sql, 'from `accounting_parties`')
                || ! str_contains($query->sql, 'order by `id`')) {
                return;
            }
            $started = true;
            $process->start();
            $this->waitForLock($process, $ipc);
        });
        $inspect = function (int $journals, int $settlements) use ($entity, $streaming): void {
            if ($streaming) {
                $export = app(StreamDatasetExporter::class)->build($entity);
                try {
                    $this->assertTrue($export['report']['valid']);
                    app(StreamDatasetVerifier::class)->verify($export['stream'], function (DatasetInspection $index) use ($journals, $settlements): void {
                        $this->assertSame($journals, (int) $index->database->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn());
                        $this->assertSame($settlements, (int) $index->database->query('SELECT COUNT(*) FROM accounting_settlements')->fetchColumn());
                        $totals = $index->database->query('SELECT SUM(CAST(base_debit_minor AS INTEGER)), SUM(CAST(base_credit_minor AS INTEGER)) FROM accounting_journal_lines')->fetch(PDO::FETCH_NUM);
                        $this->assertSame($totals[0], $totals[1]);
                    });
                } finally {
                    fclose($export['stream']);
                }
            } else {
                $export = app(AccountingDatasetExporter::class)->build($entity);
                $this->assertCount($journals, $export['dataset']['records']['accounting_journal_entries']);
                $this->assertCount($settlements, $export['dataset']['records']['accounting_settlements']);
            }
        };
        try {
            $inspect(1, 0);
            $this->assertTrue($started, 'Payment must contend with the export transaction.');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame('ok', json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR)['status']);
            $this->assertSame(0, $invoice->fresh()->openItem->remainingMinor());
            $inspect(2, 1);
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            $listening = false;
            if ($process->isRunning()) {
                $process->stop(1);
            }
            unlink($ipc);
        }
    }

    #[Test]
    public function failed_snapshot_rolls_back_and_does_not_change_subsequent_host_transactions(): void
    {
        $entity = $this->makeEntity();
        $party = $this->makeParty($entity);
        $connection = $entity->getConnection();
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $failure = new \RuntimeException('Interrupted export');
        try {
            app(DatasetSnapshot::class)->run($connection, function () use ($connection, $party, $failure): void {
                $connection->table('accounting_parties')->where('id', $party->id)->update(['display_name' => 'Must roll back']);
                throw $failure;
            });
            $this->fail('The snapshot callback must propagate its failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(0, $connection->transactionLevel());
        $connection->transaction(function () use ($connection, $party): void {
            $read = fn () => $connection->table('accounting_parties')->where('id', $party->id)->value('display_name');
            $this->assertSame($party->display_name, $read());
            $statement = $this->admin->prepare('UPDATE `'.$this->database.'`.accounting_parties SET display_name = ? WHERE id = ?');
            $statement->execute(['Host read committed', $party->id]);
            $this->assertSame('Host read committed', $read(), 'The next host transaction must retain READ COMMITTED behavior.');
        });
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function competing_operations_wait_for_the_entity_lock_and_preserve_accounting(string $scenario): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $issuer = app(IssueSalesInvoice::class);
        $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
        $invoice = $issuer->handle($entity, $payload);
        $draft = in_array($scenario, ['payment_first', 'correction_first'], true)
            ? $issuer->correct($invoice, $payload, 'Concurrent correction') : null;
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('payment-a', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
            new BankStatementLineData('payment-b', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);
        $lines = BankStatementLine::query()->orderBy('id')->get();
        $ipc = tempnam(sys_get_temp_dir(), 'acct-concurrency-');
        $job = ['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
            'operation' => $scenario === 'payment_first' ? 'issue' : 'assign', 'document' => $draft?->id,
            'line' => $lines[$scenario === 'competing_payments' ? 1 : 0]->id, 'item' => $invoice->openItem->id];
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', __FILE__, '--filter', '::worker$'],
            dirname(__DIR__, 2), ['ACCOUNTING_TEST_MYSQL_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(60);
        $connection = $entity->getConnection();
        $connection->beginTransaction();
        try {
            LegalEntity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->waitForLock($process, $ipc);
            if ($scenario === 'correction_first') {
                $winner = $issuer->issue($draft);
            } else {
                $winner = app(AssignStatementLine::class)->handle($lines[0], ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id]);
            }
            $connection->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($scenario === 'duplicate' ? 'ok' : 'rejected', $result['status']);
            if ($scenario === 'duplicate') {
                $this->assertSame($winner->id, $result['id']);
            } else {
                $this->assertSame(__('filament-accounting::errors.'.($scenario === 'payment_first'
                    ? 'invoice_correction_has_settlements' : 'invalid_allocation_target')), $result['message']);
            }
            $this->assertSame($scenario === 'correction_first' ? 0 : 1, Settlement::query()->where('is_reversed', false)->count());
            $this->assertSame($scenario === 'correction_first' ? 3 : 2, JournalEntry::query()->count());
            $this->assertSame($scenario === 'correction_first' ? 0 : 1, Reconciliation::query()->count());
            $this->assertSame($scenario === 'correction_first', $invoice->fresh()->openItem->is_reversed);
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            unlink($ipc);
        }
    }

    public static function reversalOrders(): array
    {
        return ['reversal_first' => [true], 'correction_first' => [false]];
    }

    #[Test]
    #[DataProvider('reversalOrders')]
    public function payment_reversal_and_invoice_correction_serialize_safely(bool $reversalFirst): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $issuer = app(IssueSalesInvoice::class);
        $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
        $invoice = $issuer->handle($entity, $payload);
        $draft = $issuer->correct($invoice, $payload, 'Concurrent reversal and correction');
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('reversal-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->sole();
        $payment = app(AssignStatementLine::class)->handle($line,
            ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id]);
        $settlement = Settlement::query()->sole();
        $originalAttributes = $invoice->fresh()->getAttributes();
        $beforeAudit = AuditEvent::query()->orderBy('id')->get()->toArray();
        $ipc = tempnam(sys_get_temp_dir(), 'acct-reversal-');
        $process = $this->bookingProcess(['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
            'operation' => $reversalFirst ? 'issue' : 'reverse', 'document' => $draft->id, 'reconciliation' => $payment->id]);
        $connection = $entity->getConnection();
        $connection->beginTransaction();
        try {
            LegalEntity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->waitForLock($process, $ipc);
            if ($reversalFirst) {
                app(ReverseReconciliation::class)->handle($payment, '2026-09-11', 'Correct allocation');
            } else {
                try {
                    $issuer->issue($draft);
                    $this->fail('An active payment must block invoice correction.');
                } catch (DocumentException $exception) {
                    $this->assertSame(__('filament-accounting::errors.invoice_correction_has_settlements'), $exception->getMessage());
                }
                $this->assertSame(DocumentStatus::Draft, $draft->fresh()->document_status);
                $this->assertSame(2, JournalEntry::query()->count());
                $this->assertFalse($settlement->fresh()->is_reversed);
                $this->assertFalse($invoice->fresh()->openItem->is_reversed);
                $this->assertSame($beforeAudit, AuditEvent::query()->orderBy('id')->get()->toArray());
            }
            $connection->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['status']);
            $this->assertSame($reversalFirst ? $draft->id : $payment->id, $result['id']);
            $this->assertSame(ReconciliationStatus::Reversed, $payment->fresh()->status);
            $this->assertTrue($settlement->fresh()->is_reversed);
            $this->assertSame(-11900, Settlement::query()->where('reverses_id', $settlement->id)->sole()->amount_minor);
            $this->assertSame(1, JournalEntry::query()->where('reverses_id', $payment->journal_entry_id)->count());
            if (! $reversalFirst) {
                $this->assertSame(3, JournalEntry::query()->count());
                $this->assertSame(11900, $invoice->fresh()->openItem->remainingMinor());
                $this->assertSame(0, AuditEvent::query()->where('operation', 'document.corrected')->count());
                $issuer->issue($draft->fresh());
            }
            $corrected = $draft->fresh();
            $this->assertSame($originalAttributes, $invoice->fresh()->getAttributes());
            $this->assertTrue($invoice->fresh()->openItem->is_reversed);
            $this->assertFalse($corrected->openItem->is_reversed);
            $this->assertSame(11900, $corrected->openItem->remainingMinor());
            $this->assertSame(5, JournalEntry::query()->count());
            $this->assertSame(1, AuditEvent::query()->where('operation', 'document.corrected')->count());
            $this->assertSame(1, AuditEvent::query()->where('operation', 'reconciliation.reversed')->count());
            $reassigned = app(AssignStatementLine::class)->handle($line->fresh(),
                ['purpose' => 'settle_open_item', 'open_item_id' => $corrected->openItem->id]);
            $this->assertNotSame($payment->id, $reassigned->id);
            $this->assertSame(0, $corrected->fresh()->openItem->remainingMinor());
            $this->assertSame($corrected->openItem->id, Settlement::query()->where('is_reversed', false)->sole()->open_item_id);
            $this->assertSame(6, JournalEntry::query()->count());
            $this->assertSame(3, Reconciliation::query()->count());
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
            unlink($ipc);
        }
    }

    public static function interruptionPoints(): array
    {
        return ['before_commit' => ['before_commit'], 'after_commit' => ['after_commit']];
    }

    #[Test]
    #[DataProvider('interruptionPoints')]
    public function terminated_booking_can_be_retried_without_duplicate_writes(string $phase): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('interrupted-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->sole();
        $beforeAudit = AuditEvent::query()->orderBy('id')->get()->toArray();
        $ipc = tempnam(sys_get_temp_dir(), 'acct-interruption-');
        $job = ['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
            'operation' => 'assign', 'line' => $line->id, 'item' => $invoice->openItem->id,
            'key' => 'interrupted-payment', 'pause' => $phase];
        $process = $this->bookingProcess($job);
        try {
            $process->start();
            $state = $this->waitForPause($process, $ipc);
            $this->assertSame($phase, $state['phase']);
            $this->assertSame(2, $state['journals']);
            $this->assertSame(1, $state['settlements']);
            $this->assertSame(1, $state['reconciliations']);
            $this->assertSame($phase === 'before_commit', $state['transaction_level'] > 0);
            $committed = $phase === 'after_commit';
            $this->assertSame($committed ? 1 : 0, Settlement::query()->count());
            // SIGKILL on Unix; Symfony uses taskkill /F /T on Windows.
            $process->stop(0, 9);
            $this->assertFalse($process->isRunning());

            // Acquiring the same row waits for server-side rollback after disconnect.
            $entity->getConnection()->statement('SET SESSION innodb_lock_wait_timeout = 15');
            $entity->getConnection()->transaction(fn () => LegalEntity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail());
            $this->assertSame($committed ? 2 : 1, JournalEntry::query()->count());
            $this->assertSame($committed ? 1 : 0, Reconciliation::query()->count());
            $this->assertSame($committed ? 1 : 0, Settlement::query()->count());
            $this->assertSame($committed ? 0 : 11900, $invoice->fresh()->openItem->remainingMinor());
            if (! $committed) {
                $this->assertSame($beforeAudit, AuditEvent::query()->orderBy('id')->get()->toArray());
            }
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);

            $auditBeforeRetry = AuditEvent::query()->orderBy('id')->get()->toArray();
            unset($job['pause']);
            $process = $this->bookingProcess($job);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['status']);
            if ($committed) {
                $this->assertSame($state['id'], $result['id']);
                $this->assertSame($auditBeforeRetry, AuditEvent::query()->orderBy('id')->get()->toArray());
            }
            $auditAfterRetry = AuditEvent::query()->orderBy('id')->get()->toArray();
            $replayed = app(AssignStatementLine::class)->handle($line->fresh(),
                ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id], idempotencyKey: $job['key']);
            $this->assertSame($result['id'], $replayed->id);
            $this->assertSame(2, JournalEntry::query()->count());
            $this->assertSame(1, Reconciliation::query()->count());
            $this->assertSame(1, Settlement::query()->count());
            $this->assertSame(0, $invoice->fresh()->openItem->remainingMinor());
            $this->assertSame($auditAfterRetry, AuditEvent::query()->orderBy('id')->get()->toArray());
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
            unlink($ipc);
        }
    }

    public static function artifactInterruptions(): array
    {
        return ['invoice_xml' => ['xml', false], 'invoice_pdf' => ['pdf', false],
            'correction_xml' => ['xml', true], 'correction_pdf' => ['pdf', true]];
    }

    #[Test]
    #[DataProvider('artifactInterruptions')]
    public function interrupted_invoice_files_resume_from_preserved_bytes(string $role, bool $correction): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database;
        // Never reuse or delete a pre-existing directory.
        $this->assertDirectoryDoesNotExist($root);
        $this->assertTrue(mkdir($root));
        $ipc = tempnam(sys_get_temp_dir(), 'acct-invoice-');
        $process = null;
        try {
            $this->configureArtifactStorage();
            $entity = $this->makeEntity(['address_line1' => 'Demo Street 1', 'postal_code' => '10115',
                'city' => 'Berlin', 'vat_id' => 'DE123456789']);
            $user = $this->makeUser();
            $this->actingAs($user);
            $issuer = app(IssueSalesInvoice::class);
            $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
                'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
            $original = $correction ? $issuer->handle($entity, $payload) : null;
            $originalAttributes = $original?->getAttributes();
            $originalManifest = $original?->artifactSet->manifest;
            $draft = $original ? $issuer->correct($original, $payload, 'Interrupted correction') : $issuer->createDraft($entity, $payload);
            $job = ['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
                'operation' => 'issue', 'document' => $draft->id, 'artifacts' => true, 'artifact_pause' => $role];
            $process = $this->bookingProcess($job);
            $process->start();
            $state = $this->waitForPause($process, $ipc);
            $this->assertSame('artifact_'.$role, $state['phase']);
            $this->assertGreaterThan(0, $state['transaction_level']);
            $set = InvoiceArtifactSet::query()->where('document_id', $draft->id)->sole();
            $manifest = $set->manifest;
            $evidenceHash = $set->evidence_sha256;
            $disk = Storage::disk($set->disk);
            $this->assertSame($manifest[$role]['sha256'], hash('sha256', $disk->get($manifest[$role]['path'])));
            $this->assertSame(0, $draft->attachments()->where('source_type', 'generated_'.$role)->count());
            $process->stop(0, 9);
            $this->assertFalse($process->isRunning());
            $entity->getConnection()->statement('SET SESSION innodb_lock_wait_timeout = 15');
            $entity->getConnection()->transaction(fn () => LegalEntity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail());
            $this->assertSame(DocumentStatus::Issued, $draft->fresh()->document_status);
            $this->assertSame(PostingStatus::Unposted, $draft->fresh()->posting_status);
            $this->assertSame($correction ? 1 : 0, JournalEntry::query()->count());
            $this->assertNull($set->fresh()->completed_at);
            $this->assertSame($role === 'pdf' ? ['xml' => true] : [], $set->fresh()->preserved_roles);
            $this->assertSame($role === 'pdf' ? 1 : 0, $draft->attachments()->count());
            $pending = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
            $this->assertSame([], $pending['issues']);
            $this->assertNotEmpty($pending['pending']);
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());

            unset($job['artifact_pause']);
            $process = $this->bookingProcess($job);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['status']);
            $this->assertSame($draft->id, $result['id']);
            $this->assertSame(PostingStatus::Posted, $draft->fresh()->posting_status);
            $this->assertSame($manifest, $set->fresh()->manifest);
            $this->assertSame($evidenceHash, $set->fresh()->evidence_sha256);
            $this->assertNotNull($set->fresh()->completed_at);
            $this->assertSame(2, $draft->attachments()->count());
            $this->assertSame($correction ? 2 : 1, InvoiceArtifactSet::query()->count());
            $this->assertSame($correction ? 3 : 1, JournalEntry::query()->count());
            foreach ($manifest as $file) {
                $bytes = $disk->get($file['path']);
                $this->assertSame($file['sha256'], hash('sha256', $bytes));
                $this->assertSame($file['size'], strlen($bytes));
            }
            if ($original) {
                $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
                $this->assertTrue($original->fresh()->openItem->is_reversed);
                foreach ($originalManifest as $file) {
                    $this->assertSame($file['sha256'], hash('sha256', $disk->get($file['path'])));
                }
            }
            $attachments = $draft->attachments()->orderBy('id')->get()->toArray();
            $issuer->issue($draft->fresh());
            $this->assertSame($attachments, $draft->attachments()->orderBy('id')->get()->toArray());
            $this->assertSame($correction ? 3 : 1, JournalEntry::query()->count());
            $verified = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
            $this->assertSame([], $verified['issues']);
            $this->assertSame([], $verified['pending']);
            $this->assertCount($correction ? 4 : 2, $disk->allFiles());
            // The killed attempt remains visible; a retry must not erase its history.
            $this->assertSame(1, AuditEvent::query()->where('target_id', (string) $draft->id)
                ->where('operation', 'invoice_artifacts.prepared')->count());
            $this->assertSame(3, AuditEvent::query()->where('target_id', (string) $draft->id)
                ->where('operation', 'invoice_artifacts.attempt_started')->count());
            $this->assertSame(2, AuditEvent::query()->where('target_id', (string) $draft->id)
                ->where('operation', 'invoice_artifacts.completed')->count());
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($process?->isRunning()) {
                $process->stop(0, 9);
            }
            unlink($ipc);
            Storage::forgetDisk('process_artifacts');
            $this->assertTrue(File::deleteDirectory($root));
        }
    }

    public static function concurrentArtifactStages(): array
    {
        return ['invoice_prepared' => ['prepared', false], 'invoice_pdf' => ['pdf', false],
            'correction_prepared' => ['prepared', true], 'correction_pdf' => ['pdf', true]];
    }

    #[Test]
    #[DataProvider('concurrentArtifactStages')]
    public function concurrent_issuance_preserves_one_artifact_set_and_posting(string $stage, bool $correction): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database;
        $this->assertDirectoryDoesNotExist($root);
        $this->assertTrue(mkdir($root));
        $firstIpc = tempnam(sys_get_temp_dir(), 'acct-first-');
        $secondIpc = tempnam(sys_get_temp_dir(), 'acct-second-');
        $release = tempnam(sys_get_temp_dir(), 'acct-release-');
        $first = $second = null;
        try {
            $this->configureArtifactStorage();
            $entity = $this->makeEntity(['address_line1' => 'Demo Street 1', 'postal_code' => '10115',
                'city' => 'Berlin', 'vat_id' => 'DE123456789']);
            $user = $this->makeUser();
            $this->actingAs($user);
            $issuer = app(IssueSalesInvoice::class);
            $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
                'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
            $original = $correction ? $issuer->handle($entity, $payload) : null;
            $originalAttributes = $original?->getAttributes();
            $draft = $original ? $issuer->correct($original, $payload, 'Concurrent issuance') : $issuer->createDraft($entity, $payload);
            $job = ['database' => $this->database, 'user' => $user->id, 'operation' => 'issue',
                'document' => $draft->id, 'artifacts' => true];
            $first = $this->bookingProcess($job + ['ipc' => $firstIpc, 'artifact_pause' => $stage, 'release' => $release]);
            $second = $this->bookingProcess($job + ['ipc' => $secondIpc]);
            $first->start();
            $state = $this->waitForPause($first, $firstIpc);
            $this->assertSame('artifact_'.$stage, $state['phase']);
            $this->assertGreaterThan(0, $state['transaction_level']);
            $second->start();
            $this->waitForLock($second, $secondIpc);
            $this->assertTrue($first->isRunning());
            file_put_contents($release, 'continue');
            foreach ([[$first, $firstIpc], [$second, $secondIpc]] as [$process, $ipc]) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('ok', $result['status']);
                $this->assertSame($draft->id, $result['id']);
            }
            $this->assertSame(PostingStatus::Posted, $draft->fresh()->posting_status);
            $this->assertSame($correction ? 3 : 1, JournalEntry::query()->count());
            $this->assertSame($correction ? 2 : 1, Document::query()->count());
            $this->assertSame($correction ? 2 : 1, InvoiceArtifactSet::query()->count());
            $this->assertSame(2, $draft->attachments()->count());
            $set = InvoiceArtifactSet::query()->where('document_id', $draft->id)->sole();
            $this->assertNotNull($set->completed_at);
            $events = AuditEvent::query()->where('target_type', $draft->getMorphClass())->where('target_id', (string) $draft->id);
            $this->assertSame(1, (clone $events)->where('operation', 'document.issued')->count());
            $this->assertSame(1, (clone $events)->where('operation', 'invoice_artifacts.prepared')->count());
            $this->assertSame(2, (clone $events)->where('operation', 'invoice_artifacts.completed')->count());
            $this->assertSame(0, (clone $events)->where('operation', 'invoice_artifacts.failed')->count());
            $disk = Storage::disk('process_artifacts');
            $this->assertCount($correction ? 4 : 2, $disk->allFiles());
            foreach (InvoiceArtifactSet::query()->get() as $artifactSet) {
                foreach ($artifactSet->manifest as $file) {
                    $bytes = $disk->get($file['path']);
                    $this->assertSame($file['sha256'], hash('sha256', $bytes));
                    $this->assertSame($file['size'], strlen($bytes));
                }
            }
            if ($original) {
                $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
                $this->assertTrue($original->fresh()->openItem->is_reversed);
                $this->assertFalse($draft->fresh()->openItem->is_reversed);
                $this->assertSame(1, AuditEvent::query()->where('target_type', $original->getMorphClass())
                    ->where('target_id', (string) $original->id)->where('operation', 'document.corrected')->count());
            }
            $verified = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
            $this->assertSame([], $verified['issues']);
            $this->assertSame([], $verified['pending']);
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process?->isRunning()) {
                    $process->stop(0, 9);
                }
            }
            foreach ([$firstIpc, $secondIpc, $release] as $ipc) {
                unlink($ipc);
            }
            Storage::forgetDisk('process_artifacts');
            $this->assertTrue(File::deleteDirectory($root));
        }
    }

    private function pauseArtifactWorker(string $stage): void
    {
        file_put_contents($this->job['ipc'], json_encode(['phase' => 'artifact_'.$stage,
            'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            if (isset($this->job['release']) && file_get_contents($this->job['release']) === 'continue') {
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('Parent did not release or terminate artifact worker.');
    }

    public static function storageFaults(): array
    {
        return ['xml_rejected' => ['xml', 'rejected'], 'pdf_rejected' => ['pdf', 'rejected'],
            'xml_partial' => ['xml', 'partial'], 'pdf_partial' => ['pdf', 'partial']];
    }

    #[Test]
    #[DataProvider('storageFaults')]
    public function storage_faults_block_correction_without_overwriting_evidence(string $role, string $fault): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database;
        $this->assertDirectoryDoesNotExist($root);
        $this->assertTrue(mkdir($root));
        $ipc = tempnam(sys_get_temp_dir(), 'acct-storage-');
        $process = null;
        try {
            $this->configureArtifactStorage();
            $entity = $this->makeEntity(['address_line1' => 'Demo Street 1', 'postal_code' => '10115',
                'city' => 'Berlin', 'vat_id' => 'DE123456789']);
            $user = $this->makeUser();
            $this->actingAs($user);
            $issuer = app(IssueSalesInvoice::class);
            $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
                'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
            $original = $issuer->handle($entity, $payload);
            $originalAttributes = $original->getAttributes();
            $originalFiles = $original->artifactSet->manifest;
            $draft = $issuer->correct($original, $payload, 'Storage failure correction');
            $job = ['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
                'operation' => 'issue', 'document' => $draft->id, 'artifacts' => true,
                'storage_fault' => $fault, 'storage_role' => $role];
            $process = $this->bookingProcess($job);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('rejected', $result['status']);
            $this->assertSame(__('filament-accounting::errors.'.($fault === 'partial'
                ? 'attachment_integrity_failed' : 'attachment_write_failed')), $result['message']);
            $set = InvoiceArtifactSet::query()->where('document_id', $draft->id)->sole();
            $manifest = $set->manifest;
            $disk = Storage::disk($set->disk);
            $path = $manifest[$role]['path'];
            $this->assertSame($fault === 'partial', $disk->exists($path));
            $partialBytes = $fault === 'partial' ? $disk->get($path) : null;
            if ($fault === 'partial') {
                $this->assertSame(intdiv($manifest[$role]['size'], 2), strlen($partialBytes));
            }
            $this->assertSame($role === 'pdf' ? ['xml' => true] : [], $set->preserved_roles);
            $this->assertNull($set->completed_at);
            $this->assertSame($role === 'pdf' ? 1 : 0, $draft->attachments()->count());
            $this->assertSame(PostingStatus::Unposted, $draft->fresh()->posting_status);
            $this->assertFalse($original->fresh()->openItem->is_reversed);
            $this->assertSame(1, JournalEntry::query()->count());
            $events = AuditEvent::query()->where('target_type', $draft->getMorphClass())->where('target_id', (string) $draft->id);
            $this->assertSame(1, (clone $events)->where('operation', 'invoice_artifacts.failed')->count());
            $report = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
            $this->assertNotEmpty($report['pending']);
            $this->assertSame($fault === 'partial' ? ['invoice_artifact_integrity_failed'] : [], array_column($report['issues'], 'code'));

            unset($job['storage_fault'], $job['storage_role']);
            $process = $this->bookingProcess($job);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $retry = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($manifest, $set->fresh()->manifest);
            if ($fault === 'partial') {
                $this->assertSame('rejected', $retry['status']);
                $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $retry['message']);
                $this->assertSame($partialBytes, $disk->get($path));
                $this->assertSame(PostingStatus::Unposted, $draft->fresh()->posting_status);
                $this->assertFalse($original->fresh()->openItem->is_reversed);
                $this->assertSame(1, JournalEntry::query()->count());
                $this->assertSame(2, (clone $events)->where('operation', 'invoice_artifacts.failed')->count());
                $this->assertNull($set->fresh()->completed_at);
            } else {
                $this->assertSame('ok', $retry['status']);
                $this->assertSame($draft->id, $retry['id']);
                $this->assertSame(PostingStatus::Posted, $draft->fresh()->posting_status);
                $this->assertTrue($original->fresh()->openItem->is_reversed);
                $this->assertSame(3, JournalEntry::query()->count());
                $this->assertSame(2, $draft->attachments()->count());
                foreach ($manifest as $file) {
                    $this->assertSame($file['sha256'], hash('sha256', $disk->get($file['path'])));
                }
                $report = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
                $this->assertSame([], $report['issues']);
                $this->assertSame([], $report['pending']);
            }
            $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
            foreach ($originalFiles as $file) {
                $this->assertSame($file['sha256'], hash('sha256', $disk->get($file['path'])));
            }
            $this->assertSame(2, InvoiceArtifactSet::query()->count());
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($process?->isRunning()) {
                $process->stop(0, 9);
            }
            unlink($ipc);
            Storage::forgetDisk('process_artifacts');
            $this->assertTrue(File::deleteDirectory($root));
        }
    }

    #[Test]
    public function export_and_full_fixture_restore_work_after_source_removal(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database;
        $target = 'acct_concurrency_'.bin2hex(random_bytes(12));
        $targetRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.$target;
        $this->assertDirectoryDoesNotExist($root);
        $this->assertDirectoryDoesNotExist($targetRoot);
        $this->assertTrue(mkdir($root));
        $sourceDirectoryOwned = true;
        $targetDirectoryOwned = $targetDatabaseOwned = false;
        $process = null;
        $ipc = tempnam(sys_get_temp_dir(), 'acct-restore-');
        try {
            $this->assertTrue(mkdir($targetRoot));
            $targetDirectoryOwned = true;
            $this->configureArtifactStorage();
            $entity = $this->makeEntity(['address_line1' => 'Demo Street 1', 'postal_code' => '10115',
                'city' => 'Berlin', 'vat_id' => 'DE123456789']);
            $user = $this->makeUser();
            $this->actingAs($user);
            $issuer = app(IssueSalesInvoice::class);
            $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
                'lines' => [['description' => 'Restored service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
            $original = $issuer->handle($entity, $payload);
            $invoice = $issuer->issue($issuer->correct($original, $payload, 'Restore fixture correction'));
            app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
                new BankStatementLineData('restore-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
            ]);
            $line = BankStatementLine::query()->sole();
            $payment = app(AssignStatementLine::class)->handle($line,
                ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id]);
            $export = app(StreamDatasetExporter::class)->build($entity);
            try {
                $this->assertNotFalse(file_put_contents($targetRoot.'/inspection.dataset', stream_get_contents($export['stream'])));
            } finally {
                fclose($export['stream']);
            }
            // Quiescent fixture backup: every table, including host fixture users and audit heads.
            // This trusted test snapshot is not a general importer for external SQL or JSON.
            $pdo = $entity->getConnection()->getPdo();
            $backup = $hashes = [];
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/D', $table);
                $rows = $pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
                $backup[$table] = ['ddl' => $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1], 'rows' => $rows];
                $hashes[$table] = $this->fixtureRowsHash($rows);
            }
            $this->assertNotFalse(file_put_contents($targetRoot.'/fixture-backup.json', json_encode($backup, JSON_THROW_ON_ERROR)));
            unset($backup);
            foreach (Storage::disk('process_artifacts')->allFiles() as $path) {
                File::ensureDirectoryExists(dirname($targetRoot.'/'.$path));
                $this->assertTrue(copy($root.'/'.$path, $targetRoot.'/'.$path));
            }
            // Destroy only this test's owned source; successful verification cannot fall back to it.
            DB::purge('mysql_concurrency');
            $pdo = null;
            $this->admin->exec('DROP DATABASE `'.$this->database.'`');
            $this->ownsDatabase = false;
            Storage::forgetDisk('process_artifacts');
            $this->assertTrue(File::deleteDirectory($root));
            $sourceDirectoryOwned = false;
            $this->assertDirectoryDoesNotExist($root);

            $this->admin->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $targetDatabaseOwned = true;
            $this->admin->exec('USE `'.$target.'`');
            $this->admin->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (json_decode(file_get_contents($targetRoot.'/fixture-backup.json'), true, 512, JSON_THROW_ON_ERROR) as $table => $snapshot) {
                    $this->admin->exec($snapshot['ddl']);
                    foreach ($snapshot['rows'] as $row) {
                        $columns = implode(',', array_map(fn (string $column): string => '`'.$column.'`', array_keys($row)));
                        $statement = $this->admin->prepare('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                        $statement->execute(array_values($row));
                    }
                }
            } finally {
                $this->admin->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $process = $this->bookingProcess(['database' => $target, 'ipc' => $ipc, 'user' => $user->id,
                'operation' => 'verify_restore', 'artifacts' => true, 'entity' => $entity->id, 'document' => $invoice->id,
                'line' => $line->id, 'payment' => $payment->id, 'table_hashes' => $hashes]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('restored_and_verified', $result['status']);
            $this->assertSame(count($hashes), $result['tables']);
            $this->assertSame(4, $result['files']);
            $this->assertSame(6, $result['journals_after_continuation']);
        } finally {
            if ($process?->isRunning()) {
                $process->stop(0, 9);
            }
            if ($targetDatabaseOwned) {
                $this->admin->exec('DROP DATABASE `'.$target.'`');
            }
            if ($sourceDirectoryOwned) {
                File::deleteDirectory($root);
            }
            if ($targetDirectoryOwned) {
                $this->assertTrue(File::deleteDirectory($targetRoot));
            }
            unlink($ipc);
        }
    }

    private function fixtureRowsHash(array $rows): string
    {
        $encoded = array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);

        return hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR));
    }

    private function verifyRestoredFixture(): void
    {
        $pdo = DB::connection()->getPdo();
        $this->assertCount(count($this->job['table_hashes']), $pdo->query('SHOW TABLES')->fetchAll());
        foreach ($this->job['table_hashes'] as $table => $hash) {
            $this->assertSame($hash, $this->fixtureRowsHash($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)), $table);
        }
        $entity = LegalEntity::query()->findOrFail($this->job['entity']);
        $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
        app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        $evidence = app(InvoiceEvidenceVerifier::class)->verify($entity->id);
        $this->assertSame([], $evidence['issues']);
        $this->assertSame([], $evidence['pending']);
        $stream = fopen(sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database.'/inspection.dataset', 'rb');
        try {
            $report = app(StreamDatasetVerifier::class)->verify($stream, function (DatasetInspection $index): void {
                $this->assertSame(4, (int) $index->database->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn());
                $totals = $index->database->query('SELECT SUM(CAST(base_debit_minor AS INTEGER)), SUM(CAST(base_credit_minor AS INTEGER)) FROM accounting_journal_lines')->fetch(PDO::FETCH_NUM);
                $this->assertSame([47600, 47600], array_map('intval', $totals));
                $linked = $index->database->query('SELECT d.id FROM accounting_documents d JOIN accounting_open_items o ON o.document_id=d.id JOIN accounting_settlements s ON s.open_item_id=o.id JOIN accounting_reconciliations r ON r.journal_entry_id=s.journal_entry_id JOIN accounting_bank_statement_lines b ON b.id=r.statement_line_id WHERE b.external_id=\'restore-payment\'')->fetchColumn();
                $this->assertSame((string) $this->job['document'], $linked);
                $files = $index->database->query('SELECT * FROM stored_files')->fetchAll(PDO::FETCH_ASSOC);
                $this->assertCount(4, $files);
                foreach ($files as $file) {
                    $hash = hash_init('sha256');
                    $chunks = $index->database->prepare('SELECT bytes FROM file_chunks WHERE key=? ORDER BY ordinal');
                    $chunks->execute([$file['key']]);
                    while (($bytes = $chunks->fetchColumn()) !== false) {
                        hash_update($hash, $bytes);
                    }
                    $this->assertSame($file['sha256'], hash_final($hash));
                    $this->assertSame($file['sha256'], hash('sha256', Storage::disk('process_artifacts')->get($file['path'])));
                }
            });
            $this->assertTrue($report['valid']);
        } finally {
            fclose($stream);
        }
        $invoice = Document::query()->findOrFail($this->job['document']);
        app(IssueSalesInvoice::class)->issue($invoice);
        $this->assertSame(4, JournalEntry::query()->count());
        app(ReverseReconciliation::class)->handle(Reconciliation::query()->findOrFail($this->job['payment']), '2026-09-11', 'Restored allocation review');
        $this->assertSame(11900, $invoice->fresh()->openItem->remainingMinor());
        app(AssignStatementLine::class)->handle(BankStatementLine::query()->findOrFail($this->job['line']),
            ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id]);
        $this->assertSame(0, $invoice->fresh()->openItem->remainingMinor());
        $this->assertSame(1, Settlement::query()->where('is_reversed', false)->count());
        $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
        app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        file_put_contents($this->job['ipc'], json_encode(['status' => 'restored_and_verified',
            'tables' => count($this->job['table_hashes']), 'files' => 4, 'journals_after_continuation' => JournalEntry::query()->count()], JSON_THROW_ON_ERROR));
    }

    private function configureArtifactStorage(): void
    {
        config()->set('filesystems.disks.process_artifacts', ['driver' => 'local',
            'root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->database, 'visibility' => 'private', 'throw' => true]);
        config()->set('filament-accounting.storage.disk', 'process_artifacts');
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
    }

    private function bookingProcess(array $job): Process
    {
        return (new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', __FILE__, '--filter', '::worker$'],
            dirname(__DIR__, 2), ['ACCOUNTING_TEST_MYSQL_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]))->setTimeout(60);
    }

    private function waitForPause(Process $process, string $ipc): array
    {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $state = json_decode(file_get_contents($ipc), true);
            if (isset($state['phase'])) {
                $this->assertTrue($process->isRunning());

                return $state;
            }
            if (! $process->isRunning()) {
                $this->fail('Worker exited before interruption: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(50000);
        }
        $this->fail('Worker did not reach interruption point: '.$process->getOutput().$process->getErrorOutput());
    }

    private function pauseBooking(string $phase): void
    {
        file_put_contents($this->job['ipc'], json_encode([
            'phase' => $phase, 'journals' => JournalEntry::query()->count(),
            'settlements' => Settlement::query()->count(), 'reconciliations' => Reconciliation::query()->count(),
            'id' => Reconciliation::query()->sole()->id, 'transaction_level' => DB::transactionLevel(),
        ], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            usleep(50000);
        }
        throw new \RuntimeException('Parent did not terminate paused worker.');
    }

    #[Test]
    public function transaction_sync_claim_serializes_competing_account_locks(): void
    {
        // House pattern: lockForUpdate on the bank account row before claiming a
        // transaction sync. This process-local check documents the invariant;
        // cross-process coverage remains the existing entity-lock suite.
        $entity = $this->makeEntity();
        $connection = BankConnection::query()->create([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Concurrency Connection',
            'bank_code' => 'CONC0000',
            'endpoint_url' => 'https://example.com/fints',
            'username' => 'u',
            'pin' => 'p',
            'status' => BankConnectionStatus::Active,
        ]);
        $account = $this->makeBankAccount($entity);
        $account->bank_connection_id = $connection->id;
        $account->source = 'fints';
        $account->external_account_id = 'conc-'.$account->id;
        $account->save();

        BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Running,
            'from_date' => now()->subDays(3)->toDateString(),
            'to_date' => now()->toDateString(),
            'started_at' => now(),
        ]);

        $svc = app(TransactionSyncService::class);
        $method = new \ReflectionMethod($svc, 'claimExclusiveTransactionSync');
        $method->setAccessible(true);

        $this->expectException(ConcurrentBankSyncException::class);
        $method->invoke(
            $svc,
            $account,
            $connection,
            now()->subDays(2),
            now(),
            null,
        );
    }

    private function waitForLock(Process $process, string $ipc): void
    {
        $deadline = microtime(true) + 30;
        $state = null;
        while (microtime(true) < $deadline) {
            $state = json_decode(file_get_contents($ipc), true);
            if (isset($state['connection'])) {
                $query = $this->admin->prepare('SELECT 1 FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE
                    WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ?');
                $query->execute([$state['connection'], $this->database, 'accounting_legal_entities']);
                if ($query->fetchColumn() !== false) {
                    $this->assertTrue($process->isRunning());

                    return;
                }
            }
            if (! $process->isRunning()) {
                $this->fail('Worker exited before a lock wait: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(50000);
        }
        $this->fail('MySQL did not report the worker waiting on the held entity lock. IPC: '
            .json_encode($state).' Output: '.$process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function worker(): void
    {
        $this->actingAs(User::query()->findOrFail($this->job['user']));
        DB::statement('SET SESSION innodb_lock_wait_timeout = 45');
        if ($this->job['artifacts'] ?? false) {
            $this->configureArtifactStorage();
        }
        if ($this->job['operation'] === 'verify_restore') {
            $this->verifyRestoredFixture();

            return;
        }
        if ($this->job['operation'] === 'update_export_inputs') {
            DB::transaction(function (): void {
                DB::table('accounting_parties')->where('legal_entity_id', $this->job['entity'])
                    ->where('id', $this->job['party'])->update(['display_name' => 'After snapshot']);
                DB::table('accounting_catalog_items')->where('legal_entity_id', $this->job['entity'])
                    ->where('id', $this->job['catalog'])->update(['name' => 'After snapshot']);
            });
            $this->assertSame(0, DB::transactionLevel());

            return;
        }
        if (isset($this->job['storage_fault'])) {
            $disk = Storage::disk('process_artifacts');
            $faulty = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
            {
                public string $role;

                public string $fault;

                public function put($path, $contents, $options = [])
                {
                    if (str_ends_with($path, '/'.$this->role.'.'.$this->role)) {
                        if ($this->fault === 'rejected') {
                            return false;
                        }

                        // Simulate a backend falsely reporting a successful short write.
                        return parent::put($path, substr($contents, 0, intdiv(strlen($contents), 2)), $options);
                    }

                    return parent::put($path, $contents, $options);
                }
            };
            $faulty->role = $this->job['storage_role'];
            $faulty->fault = $this->job['storage_fault'];
            Storage::set('process_artifacts', $faulty);
        }
        if (isset($this->job['artifact_pause'])) {
            AuditEvent::created(function (AuditEvent $event): void {
                if ($this->job['artifact_pause'] === 'prepared' && $event->operation === 'invoice_artifacts.prepared') {
                    $this->pauseArtifactWorker('prepared');
                }
            });
            Attachment::creating(function (Attachment $attachment): void {
                if ($attachment->source_type === 'generated_'.$this->job['artifact_pause']) {
                    $this->pauseArtifactWorker($this->job['artifact_pause']);
                }
            });
        }
        if (($this->job['pause'] ?? null) === 'before_commit') {
            AuditEvent::created(function (AuditEvent $event): void {
                if ($event->operation === 'reconciliation.finalized') {
                    $this->pauseBooking('before_commit');
                }
            });
        } elseif (($this->job['pause'] ?? null) === 'after_commit') {
            Event::listen(ReconciliationFinalized::class, fn () => $this->pauseBooking('after_commit'));
        }
        file_put_contents($this->job['ipc'], json_encode(['connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR));
        try {
            if ($this->job['operation'] === 'issue') {
                $record = app(IssueSalesInvoice::class)->issue(Document::query()->findOrFail($this->job['document']));
            } elseif ($this->job['operation'] === 'reverse') {
                $record = app(ReverseReconciliation::class)->handle(
                    Reconciliation::query()->findOrFail($this->job['reconciliation']), '2026-09-11', 'Correct allocation');
            } else {
                $record = app(AssignStatementLine::class)->handle(BankStatementLine::query()->findOrFail($this->job['line']),
                    ['purpose' => 'settle_open_item', 'open_item_id' => $this->job['item']], idempotencyKey: $this->job['key'] ?? null);
            }
            $result = ['status' => 'ok', 'id' => $record->id];
        } catch (DocumentException|ReconciliationException $exception) {
            $result = ['status' => 'rejected', 'message' => $exception->getMessage()];
        }
        file_put_contents($this->job['ipc'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertTrue(true);
    }
}

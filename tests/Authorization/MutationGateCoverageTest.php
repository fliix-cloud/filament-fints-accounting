<?php

namespace FilamentAccounting\Tests\Authorization;

use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BalanceSyncService;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\CloseAccountingPeriod;
use FilamentAccounting\Services\CreateJournalEntry;
use FilamentAccounting\Services\DeletePurchaseInvoiceDraft;
use FilamentAccounting\Services\FinalizeReconciliation;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\PostDocument;
use FilamentAccounting\Services\PurchaseInvoiceIntakeStore;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Services\ReopenAccountingPeriod;
use FilamentAccounting\Services\ReverseJournalEntry;
use FilamentAccounting\Services\ReverseReconciliation;
use FilamentAccounting\Services\SplitStatementLine;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * F3 least-privilege inventory: public mutation entry points must call authorize.
 * Console-only / internal helpers remain host residuals (see docs/gobd.md).
 */
class MutationGateCoverageTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function publicMutationServices(): array
    {
        return [
            AssignStatementLine::class,
            SplitStatementLine::class,
            FinalizeReconciliation::class,
            ReverseReconciliation::class,
            SuggestReconciliationMatches::class,
            ImportBankStatementLines::class,
            IssueSalesInvoice::class,
            GenerateInvoiceArtifacts::class,
            RegisterPurchaseInvoice::class,
            ImportPurchaseInvoice::class,
            PurchaseInvoiceIntakeStore::class,
            DeletePurchaseInvoiceDraft::class,
            PostDocument::class,
            CreateJournalEntry::class,
            ReverseJournalEntry::class,
            CloseAccountingPeriod::class,
            ReopenAccountingPeriod::class,
            TransactionSyncService::class,
            AccountSyncService::class,
            BalanceSyncService::class,
            BankingBacklogService::class,
        ];
    }

    #[Test]
    public function public_mutation_services_call_authorize(): void
    {
        foreach ($this->publicMutationServices() as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertNotFalse($source);
            $this->assertMatchesRegularExpression(
                '/->authorize\s*\(/',
                $source,
                "{$class} must call AccountingAuthorizer::authorize on public mutations.",
            );
        }
    }

    #[Test]
    public function sync_bank_gate_denies_transaction_sync_without_permission(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        Gate::define(config('filament-accounting.authorization.abilities.sync_bank'), fn (): bool => false);

        $connection = new BankConnection([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Gate Test',
            'bank_code' => 'TESTBANK00',
            'endpoint_url' => 'https://example.com/fints',
            'username' => 'testuser',
            'pin' => 'testpin',
            'status' => BankConnectionStatus::Active,
        ]);
        $connection->save();

        $account = $this->makeBankAccount($entity);
        $account->bank_connection_id = $connection->id;
        $account->source = 'fints';
        $account->external_account_id = 'acc-gate';
        $account->is_available = true;
        $account->is_enabled = true;
        $account->save();

        $this->expectException(AuthorizationException::class);
        app(TransactionSyncService::class)->sync($account);
    }
}

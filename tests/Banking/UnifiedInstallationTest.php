<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class UnifiedInstallationTest extends TestCase
{
    #[Test]
    public function a_fresh_install_has_one_canonical_bank_account_and_transaction_schema(): void
    {
        foreach ([
            'accounting_bank_accounts',
            'accounting_bank_import_runs',
            'accounting_bank_statement_lines',
            'accounting_bank_transaction_source_versions',
            'accounting_reconciliations',
            'accounting_reconciliation_splits',
            'accounting_reconciliation_learning_rules',
            'fints_bank_connections',
            'fints_bank_transfers',
            'fints_bank_direct_debits',
            'fints_direct_debit_creditor_profiles',
            'fints_direct_debit_mandates',
            'fints_sca_sessions',
            'fints_sync_runs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing unified table {$table}.");
        }

        $this->assertFalse(Schema::hasTable('fints_bank_accounts'));
        $this->assertFalse(Schema::hasTable('fints_bank_transactions'));
        $this->assertTrue(Schema::hasColumn('accounting_bank_accounts', 'source'));
        foreach (['driver_key', 'ledger_mapping_confirmed'] as $column) {
            $this->assertFalse(Schema::hasColumn('accounting_bank_accounts', $column));
        }
        $this->assertTrue(Schema::hasColumn('accounting_bank_import_runs', 'source'));
        $this->assertTrue(Schema::hasColumn('accounting_bank_statement_lines', 'source'));
        $this->assertFalse(Schema::hasColumn('accounting_party_bank_accounts', 'mandate_reference'));
    }

    #[Test]
    public function package_migrations_include_the_forward_schema_updates(): void
    {
        $migrationDirectory = __DIR__.'/../../database/migrations';
        $paths = glob($migrationDirectory.'/*.php') ?: [];
        sort($paths);

        $this->assertSame([
            '2026_08_30_000001_create_filament_accounting_tables.php',
            '2026_08_31_000002_create_accounting_party_bank_accounts.php',
            '2026_09_01_000003_create_filament_accounting_banking_tables.php',
            '2026_09_04_000004_add_tax_rule_to_reconciliation_splits.php',
            '2026_09_04_000005_add_party_contact_columns.php',
            '2026_09_10_000001_add_catalog_purchase_price_and_ean.php',
            '2026_09_10_000002_add_invoice_versions.php',
            '2026_09_10_000003_add_invoice_payment_and_layout_fields.php',
            '2026_09_11_000001_add_requested_from_date_to_fints_sync_runs.php',
            '2026_09_12_000001_add_catch_up_from_to_accounting_bank_accounts.php',
            '2026_09_18_000001_add_reconciliation_evidence_to_fints_sync_runs.php',
            '2026_09_18_000002_add_evidence_to_accounting_settlements.php',
        ], array_map('basename', $paths));

        foreach ($paths as $path) {
            $contents = (string) file_get_contents($path);
            $this->assertStringNotContainsString('Schema::hasTable(', $contents);
            $this->assertStringNotContainsString('Schema::hasColumn(', $contents);
            $this->assertStringNotContainsString('->change()', $contents);
        }
    }

    #[Test]
    public function generated_foreign_key_names_fit_the_mysql_identifier_limit(): void
    {
        $migrationDirectory = __DIR__.'/../../database/migrations';
        $paths = glob($migrationDirectory.'/*.php') ?: [];

        foreach ($paths as $path) {
            $tableName = null;
            $constraintName = null;

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match("/Schema::create\('([^']+)'/", $line, $tableMatch) === 1) {
                    $tableName = $tableMatch[1];
                }

                if ($tableName !== null && preg_match("/foreignId\('([^']+)'\)/", $line, $columnMatch) === 1) {
                    $constraintName = $tableName.'_'.$columnMatch[1].'_foreign';
                }

                if ($constraintName !== null && preg_match("/indexName:\s*'([^']+)'/", $line, $nameMatch) === 1) {
                    $constraintName = $nameMatch[1];
                }

                if ($constraintName !== null && str_contains($line, ';')) {
                    $this->assertLessThanOrEqual(
                        64,
                        strlen($constraintName),
                        "MySQL foreign key identifier {$constraintName} exceeds 64 characters.",
                    );

                    $constraintName = null;
                }
            }
        }
    }
}

<?php

namespace FilamentAccounting\Tests\Export;

use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Export\GenericJournalCsvExporter;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Support\CsvFormula;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CsvFormulaTest extends TestCase
{
    #[Test]
    public function formula_like_cells_are_prefixed_for_spreadsheet_safety(): void
    {
        $this->assertSame("'=HYPERLINK(\"https://evil\")", CsvFormula::escape('=HYPERLINK("https://evil")'));
        $this->assertSame("'+1+1", CsvFormula::escape('+1+1'));
        $this->assertSame('Bank', CsvFormula::escape('Bank'));
        $this->assertSame(100, CsvFormula::escape(100));
    }

    #[Test]
    public function unescape_restores_every_escaped_value_exactly(): void
    {
        foreach (['=SUM(A1)', '+1', '-5.00', '@cmd', "\tx", "'=x", "''+y", "'plain", 'Bank', ''] as $value) {
            $this->assertSame($value, CsvFormula::unescape(CsvFormula::escape($value)), $value);
        }

        $this->assertSame("''=x", CsvFormula::escape("'=x"));
        $this->assertSame("'plain", CsvFormula::escape("'plain"));
    }

    #[Test]
    public function journal_csv_does_not_emit_executable_formulas(): void
    {
        $entity = $this->makeEntity();
        app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-10',
            sourceType: 'manual',
            sourceId: 'csv-formula',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit((int) $entity->ledgerAccounts()->where('code', '1200')->value('id'), 100, 'EUR', '=cmd|"/c calc"!A0'),
                JournalLineDraft::credit((int) $entity->ledgerAccounts()->where('code', '8400')->value('id'), 100, 'EUR', 'Revenue'),
            ],
            idempotencyKey: 'csv-formula',
        ));

        $csv = app(GenericJournalCsvExporter::class)->export($entity, '2026-03-01', '2026-03-31');

        $this->assertStringContainsString("'=cmd|\"\"/c calc\"\"!A0", $csv);
        $this->assertDoesNotMatchRegularExpression('/^[=+\\-@]/m', $csv);
    }
}

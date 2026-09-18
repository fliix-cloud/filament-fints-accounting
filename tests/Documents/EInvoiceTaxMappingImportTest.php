<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: structured e-invoice import maps supported DE tax rates/categories and
 * fail-closes on unknown ones while preserving intake evidence.
 */
class EInvoiceTaxMappingImportTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        // Intake refuses an enclosing transaction; commit for real like PurchaseInvoiceUploadTest.
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
    }

    #[Test]
    #[DataProvider('successfulImports')]
    public function supported_tax_lines_map_onto_package_tax_codes(string $fixture, string $taxCode, int $grossMinor): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->fixture($fixture);

        $result = app(ImportPurchaseInvoice::class)->handle($entity, $fixture, $xml);

        $this->assertTrue($result->structured);
        $this->assertSame($taxCode, $result->document->lines->sole()->tax_code);
        $this->assertSame($taxCode, $result->document->lines->sole()->imported_tax_code);
        $this->assertSame($grossMinor, (int) $result->document->gross_minor);
        $this->assertSame('complete', PurchaseInvoiceIntake::query()->sole()->status);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function successfulImports(): iterable
    {
        yield 'S 19%' => ['tax-s-19.xml', 'DE-19', 11900];
        yield 'S 7%' => ['tax-s-7.xml', 'DE-7', 10700];
        yield 'Z 0%' => ['tax-z-0.xml', 'DE-0', 10000];
        yield 'E 0%' => ['tax-e-0.xml', 'DE-0', 10000];
        yield 'AE reverse charge' => ['tax-ae-0.xml', 'DE-RC', 10000];
    }

    #[Test]
    #[DataProvider('blockedImports')]
    public function unknown_tax_lines_fail_closed_and_preserve_intake(
        string $fixture,
        string $category,
        string $rateLabel,
    ): void {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $xml = $this->fixture($fixture);

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, $fixture, $xml);
            $this->fail('Unknown tax must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.unmapped_e_invoice_tax', [
                    'category' => $category,
                    'rate' => $rateLabel,
                ]),
                $exception->getMessage(),
            );
            $intake = PurchaseInvoiceIntake::query()->sole();
            $this->assertSame('blocked', $intake->status);
            $this->assertSame($xml, Storage::disk($intake->disk)->get($intake->files['primary']['path']));
            $this->assertDatabaseCount('accounting_documents', 0);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function blockedImports(): iterable
    {
        yield '21%' => ['tax-unknown-21.xml', 'S', '21%'];
        yield '10.5%' => ['tax-unknown-10-5.xml', 'S', '10.5%'];
        yield 'AE with 19%' => ['tax-ae-with-rate.xml', 'AE', '19%'];
        yield 'category L' => ['tax-unknown-category.xml', 'L', '0%'];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/ubl/'.$name);
        if ($contents === false) {
            throw new \RuntimeException('Missing fixture '.$name);
        }

        return $contents;
    }
}

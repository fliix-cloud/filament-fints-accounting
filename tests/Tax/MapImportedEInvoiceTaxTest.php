<?php

namespace FilamentAccounting\Tests\Tax;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Tax\MapImportedEInvoiceTax;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: e-invoice tax mapping — known DE rates/categories map to package tax codes;
 * unknown combinations fail closed.
 */
class MapImportedEInvoiceTaxTest extends TestCase
{
    #[Test]
    #[DataProvider('supportedMappings')]
    public function known_de_rates_and_categories_map_to_tax_codes(
        ?int $rateBp,
        ?string $category,
        string $expected,
    ): void {
        $this->assertSame($expected, app(MapImportedEInvoiceTax::class)->code($rateBp, $category));
    }

    /** @return iterable<string, array{0: ?int, 1: ?string, 2: string}> */
    public static function supportedMappings(): iterable
    {
        yield 'S 19%' => [1900, 'S', 'DE-19'];
        yield 'blank 19%' => [1900, null, 'DE-19'];
        yield 'S 7%' => [700, 'S', 'DE-7'];
        yield 'blank 7%' => [700, '', 'DE-7'];
        yield 'blank 0%' => [0, null, 'DE-0'];
        yield 'Z 0%' => [0, 'Z', 'DE-0'];
        yield 'E 0%' => [0, 'E', 'DE-0'];
        yield 'Z without rate' => [null, 'Z', 'DE-0'];
        yield 'AE reverse charge' => [0, 'AE', 'DE-RC'];
        yield 'AE without rate' => [null, 'AE', 'DE-RC'];
        yield 'K intra-community acquisition' => [0, 'K', 'DE-IG-ACQ'];
        yield 'G export' => [0, 'G', 'DE-EXPORT'];
        yield 'temporary standard 16%' => [1600, 'S', 'DE-19'];
        yield 'temporary reduced 5%' => [500, 'S', 'DE-7'];
        yield 'lowercase category' => [1900, 's', 'DE-19'];
    }

    #[Test]
    #[DataProvider('blockedMappings')]
    public function unknown_rates_and_categories_fail_closed(?int $rateBp, ?string $category): void
    {
        $mapper = app(MapImportedEInvoiceTax::class);

        try {
            $mapper->code($rateBp, $category);
            $this->fail('Unmapped tax must fail closed.');
        } catch (DocumentException $exception) {
            $this->assertSame(
                __('filament-accounting::errors.unmapped_e_invoice_tax', [
                    'category' => filled($category) ? strtoupper(trim($category)) : '—',
                    'rate' => $rateBp === null
                        ? 'missing'
                        : rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%',
                ]),
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{0: ?int, 1: ?string}> */
    public static function blockedMappings(): iterable
    {
        yield 'foreign 21%' => [2100, 'S'];
        yield 'unknown 10.5%' => [1050, 'S'];
        yield 'S with 0%' => [0, 'S'];
        yield 'AE with 19%' => [1900, 'AE'];
        yield 'unknown category L' => [0, 'L'];
        yield 'outside scope O' => [0, 'O'];
        yield 'missing rate and category' => [null, null];
        yield 'S without rate' => [null, 'S'];
    }
}

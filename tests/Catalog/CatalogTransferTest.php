<?php

namespace FilamentAccounting\Tests\Catalog;

use FilamentAccounting\Catalog\ImportExport\CatalogExporter;
use FilamentAccounting\Catalog\ImportExport\CatalogImporter;
use FilamentAccounting\Catalog\ImportExport\CatalogImportException;
use FilamentAccounting\Catalog\ImportExport\CatalogSerializer;
use FilamentAccounting\Catalog\ImportExport\CatalogTransferSchema;
use FilamentAccounting\Catalog\ImportExport\CatalogTransferUnits;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CatalogTransferTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->makeUser());
        $this->makeEntity();
        $this->path = tempnam(sys_get_temp_dir(), 'catalog-test-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    public static function formats(): array
    {
        return array_map(fn ($format) => [$format], array_keys(CatalogTransferSchema::FORMATS));
    }

    #[Test]
    #[DataProvider('formats')]
    public function templates_select_standard_tax_and_blank_import_values_default_without_overriding_explicit_codes(string $format): void
    {
        app(CatalogExporter::class)->export($this->path, $format, true);
        $this->assertSame('DE-19', array_values(app(CatalogSerializer::class)->read($this->path, $format))[0]['tax_code']);
        $rows = [
            array_replace(CatalogTransferSchema::example(), ['sku' => 'EMPTY', 'tax_code' => '']),
            array_replace(CatalogTransferSchema::example(), ['sku' => 'NULL', 'tax_code' => null]),
            array_replace(CatalogTransferSchema::example(), ['sku' => 'EXPLICIT', 'tax_code' => 'DE-7']),
        ];
        app(CatalogSerializer::class)->write($this->path, $format, $rows);
        app(CatalogImporter::class)->import($this->path, $format);
        $this->assertSame('DE-19', CatalogItem::query()->where('sku', 'EMPTY')->firstOrFail()->default_tax_code);
        $this->assertSame('DE-19', CatalogItem::query()->where('sku', 'NULL')->firstOrFail()->default_tax_code);
        $this->assertSame('DE-7', CatalogItem::query()->where('sku', 'EXPLICIT')->firstOrFail()->default_tax_code);
    }

    public static function unavailableDefaultTax(): array
    {
        return [['inactive'], ['missing']];
    }

    #[Test]
    #[DataProvider('unavailableDefaultTax')]
    public function missing_or_inactive_default_tax_fails_without_writes_or_creating_tax_codes(string $state): void
    {
        $entity = $this->makeEntity();
        TaxCode::query()->where('legal_entity_id', $entity->id)->where('code', 'DE-19')
            ->update($state === 'inactive' ? ['is_active' => false] : ['code' => 'RENAMED']);
        $taxCount = TaxCode::query()->count();
        $row = CatalogTransferSchema::example();
        unset($row['tax_code']);
        app(CatalogSerializer::class)->write($this->path, 'json', [$row]);
        try {
            app(CatalogImporter::class)->import($this->path, 'json');
            $this->fail('Inactive default accepted');
        } catch (CatalogImportException $e) {
            $this->assertStringContainsString('DE-19', $e->getMessage());
            $this->assertStringContainsString('tax_code', $e->getMessage());
            $this->assertSame(0, CatalogItem::query()->count());
            $this->assertSame($taxCount, TaxCode::query()->count());
        }
    }

    public static function spreadsheetFormats(): array
    {
        return [['xlsx'], ['xls']];
    }

    #[Test]
    #[DataProvider('spreadsheetFormats')]
    public function tax_dropdown_contains_all_and_only_active_entity_codes_even_for_long_lists(string $format): void
    {
        $foreign = $this->makeEntity();
        TaxCode::query()->create(['legal_entity_id' => $foreign->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'is_active' => true]);
        $entity = $this->makeEntity();
        TaxCode::query()->create(['legal_entity_id' => $entity->id, 'code' => 'INACTIVE', 'name' => 'Inactive', 'is_active' => false]);
        for ($i = 1; $i <= 30; $i++) {
            TaxCode::query()->create(['legal_entity_id' => $entity->id, 'code' => 'CUSTOM-CODE-'.$i, 'name' => 'Custom '.$i, 'is_active' => true]);
        }
        $expected = TaxCode::query()->where('legal_entity_id', $entity->id)->where('is_active', true)->orderBy('code')->pluck('code')->all();
        $this->assertGreaterThan(255, strlen(implode(',', $expected)));
        app(CatalogExporter::class)->export($this->path, $format, true);
        $book = IOFactory::createReader(ucfirst($format))->load($this->path);
        $this->assertSame(2, $book->getSheetCount());
        $lookup = $book->getSheetByName('_catalog_tax_codes');
        $this->assertNotNull($lookup);
        $this->assertNotSame('visible', $lookup->getSheetState());
        $actual = [];
        for ($r = 2; $r <= $lookup->getHighestDataRow(); $r++) {
            $actual[] = $lookup->getCell('A'.$r)->getValue();
        }
        $this->assertSame($expected, $actual);
        $taxValidation = array_values(array_filter($book->getSheet(0)->getDataValidationCollection(), fn ($validation) => str_contains($validation->getFormula1(), '_CatalogTaxCodes')));
        $this->assertCount(1, $taxValidation);
        $this->assertTrue($taxValidation[0]->getShowDropDown());
        $this->assertSame('DE-19', $book->getSheet(0)->getCell('J2')->getValue());
        // The helper is display metadata, never authority for creating/accepting a tax code.
        $lookup->setCellValueExplicit('A2', 'FOREIGN', DataType::TYPE_STRING);
        $book->getSheet(0)->setCellValueExplicit('J2', 'FOREIGN', DataType::TYPE_STRING);
        IOFactory::createWriter($book, ucfirst($format))->save($this->path);
        $book->disconnectWorksheets();
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, $format);
    }

    #[Test]
    #[DataProvider('formats')]
    public function overlong_names_report_the_actual_character_count_and_limit(string $format): void
    {
        app()->setLocale('de');
        app(CatalogSerializer::class)->write($this->path, $format, [array_replace(CatalogTransferSchema::example(), ['name' => str_repeat('ü', 320)])]);
        try {
            app(CatalogImporter::class)->import($this->path, $format);
            $this->fail('Overlong name accepted');
        } catch (CatalogImportException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
            $this->assertStringContainsString('320 Zeichen', $e->getMessage());
            $this->assertStringContainsString('255', $e->getMessage());
            $this->assertStringContainsString('description', $e->getMessage());
            $this->assertSame(0, CatalogItem::query()->count());
        }
    }

    #[Test]
    #[DataProvider('formats')]
    public function readable_units_round_trip_across_interface_languages_and_legacy_codes(string $format): void
    {
        app()->setLocale('de');
        app(CatalogExporter::class)->export($this->path, $format, true);
        $this->assertSame('Stück', array_values(app(CatalogSerializer::class)->read($this->path, $format))[0]['unit']);
        if (in_array($format, ['xlsx', 'xls'], true)) {
            $book = IOFactory::createReader(ucfirst($format))->load($this->path);
            $validations = $book->getActiveSheet()->getDataValidationCollection();
            $this->assertNotEmpty($validations);
            $this->assertStringContainsString('Stück,Stunde', reset($validations)->getFormula1());
            $this->assertTrue(reset($validations)->getShowDropDown());
            $book->disconnectWorksheets();
        }
        app()->setLocale('en');
        app(CatalogImporter::class)->import($this->path, $format);
        $this->assertSame('C62', CatalogItem::query()->firstOrFail()->unit);
        app(CatalogExporter::class)->export($this->path, $format);
        $this->assertSame('Piece', array_values(app(CatalogSerializer::class)->read($this->path, $format))[0]['unit']);
        app()->setLocale('de');
        $this->assertSame(1, app(CatalogImporter::class)->import($this->path, $format)->updated);
        $rows = [];
        foreach (['de', 'en'] as $language) {
            foreach (CatalogTransferUnits::labels($language) as $code => $label) {
                $rows[] = array_replace(CatalogTransferSchema::example(), ['sku' => $language.$code, 'unit' => $label]);
            }
        }
        foreach (array_keys(CatalogTransferUnits::labels()) as $code) {
            $rows[] = array_replace(CatalogTransferSchema::example(), ['sku' => 'legacy'.$code, 'unit' => $code]);
        }
        app(CatalogSerializer::class)->write($this->path, $format, $rows);
        $this->assertSame(39, app(CatalogImporter::class)->import($this->path, $format)->created);
        foreach ($rows as $row) {
            $this->assertSame(CatalogTransferUnits::code($row['unit']), CatalogItem::query()->where('sku', $row['sku'])->firstOrFail()->unit);
        }
    }

    #[Test]
    public function schema_order_is_the_documented_contract_and_optional_json_fields_may_be_omitted(): void
    {
        $this->assertSame(['sku', 'name', 'description', 'type', 'unit', 'quantity', 'sales_price', 'purchase_price', 'currency', 'tax_code', 'ean', 'active'], CatalogTransferSchema::FIELDS);
        $row = array_diff_key(CatalogTransferSchema::example(), array_flip(CatalogTransferSchema::OPTIONAL));
        app(CatalogSerializer::class)->write($this->path, 'json', [$row]);
        app(CatalogImporter::class)->import($this->path, 'json');
        $item = CatalogItem::query()->firstOrFail();
        $this->assertNull($item->sku);
        $this->assertNull($item->ean);
        $this->assertNull($item->description);
        $this->assertNull($item->purchase_price_minor);
        $this->assertSame('DE-19', $item->default_tax_code);
    }

    #[Test]
    public function an_observer_veto_rolls_back_the_entire_import(): void
    {
        $rows = [CatalogTransferSchema::example(), array_replace(CatalogTransferSchema::example(), ['sku' => 'VETO'])];
        app(CatalogSerializer::class)->write($this->path, 'json', $rows);
        CatalogItem::creating(fn (CatalogItem $item): bool => $item->sku !== 'VETO');
        try {
            app(CatalogImporter::class)->import($this->path, 'json');
            $this->fail('Observer veto ignored');
        } catch (CatalogImportException) {
            $this->assertSame(0, CatalogItem::query()->count());
        }
    }

    #[Test]
    public function currency_specific_minor_unit_scales_are_exact(): void
    {
        app(CatalogSerializer::class)->write($this->path, 'json', [
            array_replace(CatalogTransferSchema::example(), ['sku' => 'JPY', 'currency' => 'JPY', 'sales_price' => '119', 'purchase_price' => '80']),
            array_replace(CatalogTransferSchema::example(), ['sku' => 'BHD', 'currency' => 'BHD', 'sales_price' => '1.234', 'purchase_price' => '0.001']),
        ]);
        app(CatalogImporter::class)->import($this->path, 'json');
        $this->assertSame(119, CatalogItem::query()->where('sku', 'JPY')->firstOrFail()->default_unit_price_minor);
        $this->assertSame(1234, CatalogItem::query()->where('sku', 'BHD')->firstOrFail()->default_unit_price_minor);
        $this->assertSame(1, CatalogItem::query()->where('sku', 'BHD')->firstOrFail()->purchase_price_minor);
    }

    #[Test]
    #[DataProvider('formats')]
    public function all_formats_preserve_the_same_canonical_dataset(string $format): void
    {
        $rows = [CatalogTransferSchema::example(), array_replace(CatalogTransferSchema::example(), [
            'sku' => '000002', 'type' => 'service', 'unit' => 'HUR', 'active' => false,
            'description' => "Mit ; Semikolon, Komma und \"Anführungszeichen\"\r\nZweite Zeile äöü\nDritte Zeile",
            'quantity' => '0.125', 'purchase_price' => null, 'sales_price' => '90071992547409.91',
        ])];
        app(CatalogSerializer::class)->write($this->path, $format, $rows);
        $result = app(CatalogImporter::class)->import($this->path, $format);
        $this->assertSame(2, $result->created);
        $first = CatalogItem::query()->where('sku', '000123')->firstOrFail();
        $this->assertSame($rows[0]['name'], $first->name);
        $this->assertSame($rows[0]['description'], $first->description);
        $this->assertSame('04012345678901', $first->ean);
        $this->assertSame(11900, $first->default_unit_price_minor);
        $this->assertSame(8000, $first->purchase_price_minor);
        $this->assertSame(9007199254740991, CatalogItem::query()->where('sku', '000002')->firstOrFail()->default_unit_price_minor);
        app(CatalogExporter::class)->export($this->path, $format);
        $decoded = array_values(app(CatalogSerializer::class)->read($this->path, $format));
        foreach ($decoded as &$row) {
            if ($format !== 'json') {
                $row['active'] = $row['active'] === '1';
                foreach (CatalogTransferSchema::OPTIONAL as $field) {
                    $row[$field] = $row[$field] === '' ? null : $row[$field];
                }
            }
        }
        unset($row);
        // Excel normalizes CRLF to LF; line-break semantics and all other characters must survive.
        foreach ($rows as &$row) {
            $row['unit'] = CatalogTransferUnits::label(CatalogTransferUnits::code($row['unit']));
            if (in_array($format, ['xlsx', 'xls'], true)) {
                $row['description'] = str_replace("\r\n", "\n", $row['description']);
            }
        }
        unset($row);
        $this->assertSame($rows, $decoded);
        CatalogItem::query()->delete();
        $this->assertSame(2, app(CatalogImporter::class)->import($this->path, $format)->created);
        $this->assertSame($rows[1]['description'], CatalogItem::query()->where('sku', '000002')->firstOrFail()->description);
        if ($format === 'csv' || $format === 'json') {
            $text = file_get_contents($this->path);
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
            $this->assertStringContainsString($rows[0]['name'], $text);
            $this->assertFalse(str_starts_with($text, "\xEF\xBB\xBF"));
        }
    }

    #[Test]
    public function csv_export_neutralizes_formulas_and_round_trips_them(): void
    {
        $rows = [array_replace(CatalogTransferSchema::example(), [
            'name' => '=HYPERLINK("https://evil.example","Klick")',
            'description' => "'=bereits mit Apostroph",
        ])];
        app(CatalogSerializer::class)->write($this->path, 'csv', $rows);

        $text = (string) file_get_contents($this->path);
        $this->assertDoesNotMatchRegularExpression('/(^|;)"?[=+\-@]/m', $text);

        $decoded = array_values(app(CatalogSerializer::class)->read($this->path, 'csv'));
        $this->assertSame($rows[0]['name'], $decoded[0]['name']);
        $this->assertSame($rows[0]['description'], $decoded[0]['description']);
    }

    #[Test]
    #[DataProvider('formats')]
    public function templates_are_importable_and_empty_catalog_exports_are_valid_noops(string $format): void
    {
        app(CatalogExporter::class)->export($this->path, $format);
        $this->assertSame(0, app(CatalogImporter::class)->import($this->path, $format)->created);
        app(CatalogExporter::class)->export($this->path, $format, true);
        $this->assertSame(1, app(CatalogImporter::class)->import($this->path, $format)->created);
    }

    public static function legacyEncodings(): array
    {
        return [['bom'], ['cp1252']];
    }

    #[Test]
    #[DataProvider('legacyEncodings')]
    public function csv_supports_bom_and_explicit_windows_1252(string $encoding): void
    {
        app(CatalogExporter::class)->export($this->path, 'csv', true);
        $text = file_get_contents($this->path);
        file_put_contents($this->path, $encoding === 'bom' ? "\xEF\xBB\xBF".$text : mb_convert_encoding($text, 'Windows-1252', 'UTF-8'));
        app(CatalogImporter::class)->import($this->path, 'csv');
        $this->assertSame(CatalogTransferSchema::example()['name'], CatalogItem::query()->firstOrFail()->name);
        $this->assertSame(CatalogTransferSchema::example()['description'], CatalogItem::query()->firstOrFail()->description);
    }

    public static function invalidFields(): array
    {
        return [
            ['name', ''], ['name', str_repeat('a', 256)], ['name', null], ['type', 'Produkt'], ['unit', 'piece'],
            ['currency', 'XXX_INVALID'], ['currency', 'eur'], ['quantity', '1,5'], ['quantity', '1e2'],
            ['sales_price', '0.001'], ['sales_price', '1,00'], ['sales_price', '1e2'], ['sales_price', 1.23],
            ['sales_price', '99999999999999999999999999999'], ['purchase_price', '80.001'],
            ['tax_code', 'MISSING'], ['active', 1], ['active', 'true'], ['sku', 123], ['ean', 123],
            ['legal_entity_id', 999], ['id', 123], ['description', "bad\x00text"],
        ];
    }

    #[Test]
    #[DataProvider('invalidFields')]
    public function invalid_dataset_is_rejected_before_any_model_save(string $field, mixed $value): void
    {
        $writes = 0;
        CatalogItem::saving(function () use (&$writes): void {
            $writes++;
        });
        $rows = [CatalogTransferSchema::example(), array_replace(CatalogTransferSchema::example(), ['sku' => 'OTHER', $field => $value])];
        app(CatalogSerializer::class)->write($this->path, 'json', $rows);
        try {
            app(CatalogImporter::class)->import($this->path, 'json');
            $this->fail('Invalid dataset was accepted');
        } catch (CatalogImportException $e) {
            $this->assertStringContainsString($field, $e->getMessage());
            $this->assertSame(0, $writes);
            $this->assertSame(0, CatalogItem::query()->count());
        }
    }

    #[Test]
    public function preview_update_skip_duplicate_and_empty_sku_behave_as_specified(): void
    {
        app(CatalogExporter::class)->export($this->path, 'json', true);
        $this->assertSame(1, app(CatalogImporter::class)->preview($this->path, 'json')->created);
        $this->assertSame(0, CatalogItem::query()->count());
        app(CatalogImporter::class)->import($this->path, 'json');
        $item = CatalogItem::query()->firstOrFail();
        $item->update(['name' => 'Changed', 'default_account_role' => 'revenue']);
        $this->assertSame(1, app(CatalogImporter::class)->import($this->path, 'json', false)->skipped);
        $this->assertSame('Changed', $item->fresh()->name);
        $this->assertSame(1, app(CatalogImporter::class)->import($this->path, 'json')->updated);
        $this->assertSame('revenue', $item->fresh()->default_account_role);
        app(CatalogSerializer::class)->write($this->path, 'json', [CatalogTransferSchema::example(), CatalogTransferSchema::example()]);
        try {
            app(CatalogImporter::class)->import($this->path, 'json');
            $this->fail('Duplicate accepted');
        } catch (CatalogImportException) {
            $this->assertSame(1, CatalogItem::query()->count());
        }
        $emptySku = array_replace(CatalogTransferSchema::example(), ['sku' => null]);
        app(CatalogSerializer::class)->write($this->path, 'json', [$emptySku, $emptySku]);
        $this->assertSame(2, app(CatalogImporter::class)->import($this->path, 'json')->created);
    }

    #[Test]
    public function imports_and_exports_are_isolated_and_tax_codes_must_be_active_and_local(): void
    {
        $first = $this->makeEntity(['legal_name' => 'First']);
        app(CatalogExporter::class)->export($this->path, 'json', true);
        app(CatalogImporter::class)->import($this->path, 'json');
        $foreignTax = TaxCode::query()->create(['legal_entity_id' => $first->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'is_active' => true]);
        $second = $this->makeEntity(['legal_name' => 'Second']);
        app(CatalogExporter::class)->export($this->path, 'json');
        $this->assertSame([], app(CatalogSerializer::class)->read($this->path, 'json'));
        foreach ([$foreignTax->code, 'INACTIVE'] as $code) {
            TaxCode::query()->firstOrCreate(['legal_entity_id' => $second->id, 'code' => 'INACTIVE'], ['name' => 'Inactive', 'is_active' => false]);
            app(CatalogSerializer::class)->write($this->path, 'json', [array_replace(CatalogTransferSchema::example(), ['tax_code' => $code])]);
            try {
                app(CatalogImporter::class)->import($this->path, 'json');
                $this->fail('Invalid tax accepted');
            } catch (CatalogImportException) {
                $this->assertSame(0, CatalogItem::query()->where('legal_entity_id', $second->id)->count());
            }
        }
        $tax = TaxCode::query()->where('legal_entity_id', $second->id)->where('is_active', true)->firstOrFail();
        app(CatalogSerializer::class)->write($this->path, 'json', [array_replace(CatalogTransferSchema::example(), ['tax_code' => $tax->code])]);
        $this->assertSame(1, app(CatalogImporter::class)->import($this->path, 'json')->created);
        $this->assertSame(2, CatalogItem::query()->count());
        app(SingleLegalEntityResolver::class)->bind($first);
        app(CatalogExporter::class)->export($this->path, 'json');
        $this->assertCount(1, app(CatalogSerializer::class)->read($this->path, 'json'));
    }

    #[Test]
    public function unexpected_persistence_failure_rolls_back_creates_and_updates(): void
    {
        app(CatalogExporter::class)->export($this->path, 'json', true);
        app(CatalogImporter::class)->import($this->path, 'json');
        $rows = [array_replace(CatalogTransferSchema::example(), ['name' => 'Updated']), array_replace(CatalogTransferSchema::example(), ['sku' => 'NEW'])];
        app(CatalogSerializer::class)->write($this->path, 'json', $rows);
        CatalogItem::creating(function (): void {
            throw new \RuntimeException('Injected failure');
        });
        try {
            app(CatalogImporter::class)->import($this->path, 'json');
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected failure', $e->getMessage());
            $this->assertSame(1, CatalogItem::query()->count());
            $this->assertSame(CatalogTransferSchema::example()['name'], CatalogItem::query()->firstOrFail()->name);
        }
    }

    public static function invalidJson(): array
    {
        $base = ['schema' => CatalogTransferSchema::NAME, 'version' => 1, 'items' => []];

        return [['{'], [json_encode(array_replace($base, ['schema' => 'other']))], [json_encode(array_replace($base, ['version' => '1']))],
            [json_encode(array_replace($base, ['version' => 2]))], [json_encode(array_replace($base, ['items' => new \stdClass]))],
            [json_encode(array_replace($base, ['extra' => 1]))], [json_encode(['schema' => CatalogTransferSchema::NAME, 'version' => 1])],
            ['[]'], [json_encode(array_replace($base, ['items' => [[]]]))], ["{\"name\":\"\xFC\"}"]];
    }

    #[Test]
    #[DataProvider('invalidJson')]
    public function malformed_json_contracts_are_rejected(string $json): void
    {
        file_put_contents($this->path, $json);
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, 'json');
    }

    public static function badHeaders(): array
    {
        return [['missing'], ['unknown'], ['duplicate'], ['order'], ['vendor']];
    }

    #[Test]
    #[DataProvider('badHeaders')]
    public function csv_headers_are_exact(string $kind): void
    {
        $fields = CatalogTransferSchema::FIELDS;
        match ($kind) {
            'missing' => array_pop($fields),
            'unknown' => $fields[] = 'foreign',
            'duplicate' => $fields[1] = 'sku',
            'order' => $fields = array_reverse($fields),
            'vendor' => $fields = ['ARTIKELNR', 'KURZBEZEICHNUNG', 'VK PREIS'],
        };
        file_put_contents($this->path, implode(';', $fields)."\r\n");
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, 'csv');
    }

    public static function badCsv(): array
    {
        return [["\xFF\xFE\x00"], ["\x81"], ['"unclosed'], ['bad"quote'], ['"closed"bad'], ['too;few']];
    }

    #[Test]
    #[DataProvider('badCsv')]
    public function malformed_csv_encoding_quoting_and_rows_are_rejected_without_writes(string $line): void
    {
        file_put_contents($this->path, implode(';', CatalogTransferSchema::FIELDS)."\r\n".$line);
        try {
            app(CatalogImporter::class)->import($this->path, 'csv');
            $this->fail('Bad CSV accepted');
        } catch (CatalogImportException) {
            $this->assertSame(0, CatalogItem::query()->count());
        }
    }

    #[Test]
    public function csv_errors_use_physical_line_numbers_after_multiline_values(): void
    {
        $rows = [CatalogTransferSchema::example(), array_replace(CatalogTransferSchema::example(), ['sku' => 'SECOND', 'unit' => 'wrong'])];
        app(CatalogSerializer::class)->write($this->path, 'csv', $rows);
        try {
            app(CatalogImporter::class)->import($this->path, 'csv');
            $this->fail('Bad row accepted');
        } catch (CatalogImportException $e) {
            $this->assertStringContainsString('4', $e->getMessage());
            $this->assertSame(0, CatalogItem::query()->count());
        }
    }

    public static function spreadsheetErrors(): array
    {
        $cases = [];
        foreach (['xlsx', 'xls'] as $format) {
            foreach (['sheet', 'header', 'extra', 'formula', 'numeric_sku', 'numeric_ean', 'boolean'] as $error) {
                $cases[] = [$format, $error];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('spreadsheetErrors')]
    public function spreadsheets_reject_wrong_structure_formulas_and_numeric_identifiers(string $format, string $error): void
    {
        app(CatalogExporter::class)->export($this->path, $format, true);
        $book = IOFactory::createReader(ucfirst($format))->load($this->path);
        $sheet = $book->getActiveSheet();
        match ($error) {
            'sheet' => $book->createSheet(),
            'header' => $sheet->setCellValue('A1', 'name'),
            'extra' => $sheet->setCellValue('M1', 'foreign'),
            'formula' => $sheet->setCellValue('G2', '=1+1'),
            'numeric_sku' => $sheet->setCellValueExplicit('A2', 123, DataType::TYPE_NUMERIC),
            'numeric_ean' => $sheet->setCellValueExplicit('K2', 123, DataType::TYPE_NUMERIC),
            'boolean' => $sheet->setCellValueExplicit('L2', true, DataType::TYPE_BOOL),
        };
        IOFactory::createWriter($book, ucfirst($format))->save($this->path);
        $book->disconnectWorksheets();
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, $format);
    }

    #[Test]
    public function missing_json_required_fields_and_invalid_csv_booleans_are_rejected(): void
    {
        foreach (['name', 'type', 'unit', 'quantity', 'sales_price', 'currency', 'active'] as $field) {
            $row = CatalogTransferSchema::example();
            unset($row[$field]);
            app(CatalogSerializer::class)->write($this->path, 'json', [$row]);
            try {
                app(CatalogImporter::class)->import($this->path, 'json');
                $this->fail('Missing field accepted');
            } catch (CatalogImportException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
        app(CatalogExporter::class)->export($this->path, 'csv', true);
        file_put_contents($this->path, preg_replace('/;1\r\n$/', ";true\r\n", file_get_contents($this->path)));
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, 'csv');
    }

    #[Test]
    public function catalog_permission_is_required_for_import_preview_export_and_template(): void
    {
        app(CatalogExporter::class)->export($this->path, 'json', true);
        Gate::define(config('filament-accounting.authorization.abilities.manage_catalog'), fn () => false);
        foreach (['import', 'preview', 'export', 'template'] as $action) {
            try {
                match ($action) {
                    'import' => app(CatalogImporter::class)->import($this->path, 'json'),
                    'preview' => app(CatalogImporter::class)->preview($this->path, 'json'),
                    'export' => app(CatalogExporter::class)->export($this->path, 'json'),
                    'template' => app(CatalogExporter::class)->export($this->path, 'json', true),
                };
                $this->fail('Unauthorized transfer accepted');
            } catch (AuthorizationException) {
                $this->assertSame(0, CatalogItem::query()->count());
            }
        }
    }

    #[Test]
    public function unsupported_empty_and_mislabelled_files_are_rejected(): void
    {
        foreach (['pdf', 'csv', 'xlsx', 'xls', 'json'] as $format) {
            file_put_contents($this->path, '');
            try {
                app(CatalogImporter::class)->import($this->path, $format);
                $this->fail('Empty file accepted');
            } catch (CatalogImportException) {
                $this->assertSame(0, CatalogItem::query()->count());
            }
        }
        app(CatalogExporter::class)->export($this->path, 'json', true);
        $this->expectException(CatalogImportException::class);
        app(CatalogImporter::class)->import($this->path, 'xls');
    }
}

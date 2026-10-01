<?php

namespace FilamentAccounting\Catalog\ImportExport;

use FilamentAccounting\Support\CsvFormula;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Format decoding only; domain validation is shared by all serializers. */
final class CatalogSerializer
{
    private const TAX_SHEET = '_catalog_tax_codes';

    /** @return array<int, array<string, mixed>> Physical row numbers, or one-based JSON item indexes. */
    public function read(string $path, string $format): array
    {
        CatalogTransferSchema::format($format);
        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0 || filesize($path) > CatalogTransferSchema::MAX_BYTES) {
            throw CatalogImportException::because('unreadable');
        }
        try {
            $rows = match ($format) {
                'json' => $this->readJson(file_get_contents($path)),
                'csv' => $this->readCsv(file_get_contents($path)),
                default => $this->readSpreadsheet($path, $format),
            };
            if (count($rows) > CatalogTransferSchema::MAX_ROWS) {
                throw CatalogImportException::because('limit');
            }

            return $rows;
        } catch (CatalogImportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CatalogImportException(__('filament-accounting::catalog_transfer.unreadable'), 0, $e);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readJson(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw CatalogImportException::because('encoding');
        }
        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CatalogImportException::because('json');
        }
        if (! $data instanceof \stdClass || array_diff(array_keys(get_object_vars($data)), ['schema', 'version', 'items'])
            || ($data->schema ?? null) !== CatalogTransferSchema::NAME || ($data->version ?? null) !== CatalogTransferSchema::VERSION
            || ! is_array($data->items ?? null)) {
            throw CatalogImportException::because('json');
        }
        $rows = [];
        foreach ($data->items as $index => $item) {
            if (! $item instanceof \stdClass) {
                throw CatalogImportException::because('item', ['position' => $index + 1, 'field' => 'items']);
            }
            $rows[$index + 1] = get_object_vars($item);
        }

        return $rows;
    }

    private function csvUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            // Undefined CP1252 bytes are not a safe fallback. Never substitute them.
            if (preg_match('/[\x81\x8D\x8F\x90\x9D]/', $text)) {
                throw CatalogImportException::because('encoding');
            }
            $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (mb_convert_encoding($converted, 'Windows-1252', 'UTF-8') !== $text) {
                throw CatalogImportException::because('encoding');
            }
            $text = $converted;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FFFD}/u', $text)) {
            throw CatalogImportException::because('encoding');
        }

        return $text;
    }

    /** Reject malformed quoting that PHP's otherwise useful CSV parser tolerates. */
    private function validateCsvQuotes(string $text): void
    {
        $state = 'start';
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }

                continue;
            }
            if ($char === ';' || $char === "\r" || $char === "\n") {
                $state = 'start';
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                throw CatalogImportException::because('csv');
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            throw CatalogImportException::because('csv');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readCsv(string $text): array
    {
        $text = $this->csvUtf8($text);
        $this->validateCsvQuotes($text);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $text);
            rewind($stream);
            CatalogTransferSchema::headers(fgetcsv($stream, null, ';', '"', '') ?: []);
            $rows = [];
            $offset = ftell($stream);
            $position = 1 + preg_match_all('/\r\n|\r|\n/', substr($text, 0, $offset));
            while (($values = fgetcsv($stream, null, ';', '"', '')) !== false) {
                if (count($values) !== count(CatalogTransferSchema::FIELDS)) {
                    throw CatalogImportException::because('row', ['position' => $position, 'field' => implode(', ', CatalogTransferSchema::FIELDS)]);
                }
                $rows[$position] = array_combine(CatalogTransferSchema::FIELDS, array_map(CsvFormula::unescape(...), $values));
                $next = ftell($stream);
                $position += preg_match_all('/\r\n|\r|\n/', substr($text, $offset, $next - $offset));
                $offset = $next;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function readSpreadsheet(string $path, string $format): array
    {
        $reader = IOFactory::createReader($format === 'xlsx' ? 'Xlsx' : 'Xls');
        if (! $reader->canRead($path)) {
            throw CatalogImportException::because('unreadable');
        }
        $info = $reader->listWorksheetInfo($path);
        if (count($info) < 1 || count($info) > 2
            || (count($info) === 2 && ($info[1]['worksheetName'] !== self::TAX_SHEET || $info[1]['totalColumns'] !== 2 || $info[1]['totalRows'] > CatalogTransferSchema::MAX_ROWS + 1))
            || $info[0]['totalRows'] > CatalogTransferSchema::MAX_ROWS + 1
            || $info[0]['totalColumns'] !== count(CatalogTransferSchema::FIELDS)) {
            throw CatalogImportException::because('worksheet');
        }
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            if ($sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE
                || ($book->getSheetCount() === 2 && $book->getSheet(1)->getSheetState() === Worksheet::SHEETSTATE_VISIBLE)) {
                throw CatalogImportException::because('worksheet');
            }
            $rows = [];
            for ($r = 1; $r <= $sheet->getHighestDataRow(); $r++) {
                $values = [];
                foreach (CatalogTransferSchema::FIELDS as $c => $field) {
                    $cell = $sheet->getCell([$c + 1, $r]);
                    $value = $cell->getValue();
                    if (in_array($cell->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)
                        || ($r > 1 && in_array($field, ['sku', 'ean'], true) && $value !== null && ! is_string($value))) {
                        throw CatalogImportException::because('row', ['position' => $r, 'field' => $field]);
                    }
                    // Numeric Excel cells are decoded once to their decimal text, never used in money arithmetic.
                    $values[] = is_int($value) || is_float($value) ? (string) $value : $value;
                }
                if ($r === 1) {
                    CatalogTransferSchema::headers($values);
                } elseif (array_filter($values, static fn (mixed $value): bool => $value !== null) !== []) {
                    // Excel can materialize an empty cell at the start of a validation range.
                    $rows[$r] = array_combine(CatalogTransferSchema::FIELDS, $values);
                }
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $taxCodes
     */
    public function write(string $path, string $format, array $rows, array $taxCodes = []): void
    {
        CatalogTransferSchema::format($format);
        if (count($rows) > CatalogTransferSchema::MAX_ROWS) {
            throw CatalogImportException::because('limit');
        }
        if ($format === 'json') {
            file_put_contents($path, json_encode(['schema' => CatalogTransferSchema::NAME, 'version' => CatalogTransferSchema::VERSION, 'items' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $stream = fopen($path, 'wb');
            try {
                fputcsv($stream, CatalogTransferSchema::FIELDS, ';', '"', '', "\r\n");
                foreach ($rows as $row) {
                    $row['active'] = $row['active'] ? '1' : '0';
                    // Neutralize spreadsheet formulas; readCsv() reverses this.
                    fputcsv($stream, array_map(fn (string $field) => CsvFormula::escape($row[$field]), CatalogTransferSchema::FIELDS), ';', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        } else {
            $book = new Spreadsheet;
            try {
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('Catalog');
                foreach ([CatalogTransferSchema::FIELDS, ...array_map(function (array $row): array {
                    $row['active'] = $row['active'] ? '1' : '0';

                    return array_map(fn (string $field) => $row[$field] ?? '', CatalogTransferSchema::FIELDS);
                }, $rows)] as $r => $values) {
                    foreach ($values as $c => $value) {
                        $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
                    }
                }
                $sheet->getStyle('C')->getAlignment()->setWrapText(true);
                $unitColumn = Coordinate::stringFromColumnIndex(array_search('unit', CatalogTransferSchema::FIELDS, true) + 1);
                $validation = (new DataValidation)->setType(DataValidation::TYPE_LIST)
                    ->setAllowBlank(false)->setShowDropDown(true)->setShowInputMessage(true)
                    ->setPromptTitle(__('filament-accounting::fields.unit'))
                    ->setPrompt(__('filament-accounting::catalog_transfer.unit_help'))
                    ->setFormula1('"'.implode(',', CatalogTransferUnits::labels()).'"');
                $sheet->setDataValidation($unitColumn.'2:'.$unitColumn.(CatalogTransferSchema::MAX_ROWS + 1), $validation);
                $sheet->getColumnDimension($unitColumn)->setWidth(20);
                $sheet->getComment($unitColumn.'1')->getText()->createText(__('filament-accounting::catalog_transfer.unit_help'));
                if ($taxCodes !== []) {
                    $this->addTaxCodeDropdown($book, $taxCodes);
                }
                $sheet->freezePane('A2');
                $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count(CatalogTransferSchema::FIELDS)).'1')->getFont()->setBold(true);
                IOFactory::createWriter($book, $format === 'xlsx' ? 'Xlsx' : 'Xls')->save($path);
            } finally {
                $book->disconnectWorksheets();
            }
        }
        if (filesize($path) > CatalogTransferSchema::MAX_BYTES) {
            throw CatalogImportException::because('limit');
        }
    }

    /** @param array<string, string> $taxCodes */
    private function addTaxCodeDropdown(Spreadsheet $book, array $taxCodes): void
    {
        if (count($taxCodes) > CatalogTransferSchema::MAX_ROWS) {
            throw CatalogImportException::because('limit');
        }
        // A range avoids Excel's 255-character limit for inline dropdown lists.
        $lookup = $book->createSheet()->setTitle(self::TAX_SHEET);
        $lookup->setCellValueExplicit('A1', 'tax_code', DataType::TYPE_STRING);
        $lookup->setCellValueExplicit('B1', 'name', DataType::TYPE_STRING);
        $row = 2;
        foreach ($taxCodes as $code => $name) {
            $lookup->setCellValueExplicit('A'.$row, (string) $code, DataType::TYPE_STRING);
            $lookup->setCellValueExplicit('B'.$row, $name, DataType::TYPE_STRING);
            $row++;
        }
        $lookup->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->addNamedRange(new NamedRange('_CatalogTaxCodes', $lookup, '$A$2:$A$'.($row - 1)));
        $sheet = $book->getSheet(0);
        $column = Coordinate::stringFromColumnIndex(array_search('tax_code', CatalogTransferSchema::FIELDS, true) + 1);
        $validation = (new DataValidation)->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)
            ->setShowDropDown(true)->setShowInputMessage(true)->setShowErrorMessage(true)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setPromptTitle(__('filament-accounting::fields.tax_code'))
            ->setPrompt(__('filament-accounting::catalog_transfer.tax_help'))
            ->setError(__('filament-accounting::catalog_transfer.tax_help'))
            // INDIRECT also survives the XLS writer, which cannot encode direct named-range formulas.
            ->setFormula1('INDIRECT("_CatalogTaxCodes")');
        $sheet->setDataValidation($column.'2:'.$column.(CatalogTransferSchema::MAX_ROWS + 1), $validation);
        $sheet->getColumnDimension($column)->setWidth(22);
        $sheet->getComment($column.'1')->getText()->createText(__('filament-accounting::catalog_transfer.tax_help'));
        $book->setActiveSheetIndex(0);
    }
}

<?php

namespace FilamentAccounting\Tax;

use FilamentAccounting\Exceptions\DocumentException;

/**
 * Maps EN 16931 / UNTDID 5305 tax category + rate from a structured e-invoice
 * line onto the package's German tax codes. Unknown combinations fail closed.
 *
 * Scope: DE EUR purchase-import workflows. No FX; foreign VAT rates are rejected.
 */
final class MapImportedEInvoiceTax
{
    /**
     * @throws DocumentException when the rate/category cannot be mapped safely
     */
    public function code(?int $rateBp, ?string $category): string
    {
        $normalized = strtoupper(trim((string) $category));

        return match ($normalized) {
            'AE' => $this->zeroRated($rateBp, $normalized, 'DE-RC'),
            'K' => $this->zeroRated($rateBp, $normalized, 'DE-IG-ACQ'),
            'G' => $this->zeroRated($rateBp, $normalized, 'DE-EXPORT'),
            'E', 'Z' => $this->zeroRated($rateBp, $normalized, 'DE-0'),
            'S', '' => $this->standardOrBlank($rateBp, $normalized),
            default => throw $this->unmapped($rateBp, $normalized),
        };
    }

    private function standardOrBlank(?int $rateBp, string $category): string
    {
        if ($rateBp === null) {
            throw $this->unmapped($rateBp, $category !== '' ? $category : null);
        }

        // S (standard) with an explicit 0% is inconsistent — use Z/E instead.
        if ($category === 'S' && $rateBp === 0) {
            throw $this->unmapped($rateBp, $category);
        }

        return match ($rateBp) {
            // Current and temporary COVID standard / reduced rates share DE-19 / DE-7;
            // ResolveTaxRuleVersion picks the version that matches the document date.
            1900, 1600 => 'DE-19',
            700, 500 => 'DE-7',
            0 => 'DE-0',
            default => throw $this->unmapped($rateBp, $category !== '' ? $category : null),
        };
    }

    private function zeroRated(?int $rateBp, string $category, string $taxCode): string
    {
        if ($rateBp !== null && $rateBp !== 0) {
            throw $this->unmapped($rateBp, $category);
        }

        return $taxCode;
    }

    private function unmapped(?int $rateBp, ?string $category): DocumentException
    {
        $rateLabel = $rateBp === null
            ? 'missing'
            : rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%';

        return new DocumentException(__('filament-accounting::errors.unmapped_e_invoice_tax', [
            'category' => $category ?? '—',
            'rate' => $rateLabel,
        ]));
    }
}

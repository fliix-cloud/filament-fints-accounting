<?php

namespace FilamentAccounting\Documents;

/**
 * Shared DE-EUR subset constants for reception and issuing.
 *
 * VAT shape is BR-CO-09 without a checksum or VIES lookup.
 * EAS codes are the documented German-relevant slice, not the full CEF list.
 */
final class EInvoiceSubsetCatalog
{
    /** @var list<string> */
    public const EAS_SCHEMES = ['EM', '0060', '0088', '0204', '0246', '9930'];

    public static function easSchemeAllowed(string $scheme): bool
    {
        return in_array(strtoupper(trim($scheme)), self::EAS_SCHEMES, true);
    }

    public static function vatIdentifierIsValid(string $value): bool
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', '', trim($value)));
        if (str_starts_with($normalized, 'DE')) {
            return preg_match('/^DE[0-9]{9}$/', $normalized) === 1;
        }

        return preg_match('/^(EL|[A-Z]{2})[A-Z0-9]{2,12}$/', $normalized) === 1;
    }
}

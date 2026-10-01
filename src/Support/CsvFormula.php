<?php

namespace FilamentAccounting\Support;

final class CsvFormula
{
    /**
     * Leading apostrophes are counted so that unescape() restores the exact
     * original value, including one that itself starts with an apostrophe.
     */
    private const PATTERN = "/^'*[=+\\-@\\t\\r]/";

    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match(self::PATTERN, $value) === 1 ? "'".$value : $value;
    }

    public static function unescape(mixed $value): mixed
    {
        if (! is_string($value) || ! str_starts_with($value, "'")) {
            return $value;
        }

        return preg_match(self::PATTERN, $value) === 1 ? substr($value, 1) : $value;
    }
}

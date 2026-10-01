<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;

final class EndpointValidator
{
    public static function validate(string $url): string
    {
        $url = self::validateSyntax($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! config('filament-accounting.banking.fints.security.allow_private_endpoints', false) && self::isPrivateHost($host)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_private'));
        }

        return $url;
    }

    /**
     * Scheme, credential, and allow-list checks without DNS resolution. Used for
     * bulk directory imports; validate() runs again before any connection.
     */
    public static function validateSyntax(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_required'));
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_invalid'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_credentials'));
        }

        $httpsOnly = (bool) config('filament-accounting.banking.fints.security.https_only', true);

        if ($httpsOnly && strtolower($parts['scheme']) !== 'https') {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_https'));
        }

        if (! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_scheme'));
        }

        $host = strtolower($parts['host']);
        $allowed = config('filament-accounting.banking.fints.security.allowed_hosts', []);

        if (is_array($allowed) && $allowed !== [] && ! in_array($host, $allowed, true)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.endpoint_host'));
        }

        return $url;
    }

    private static function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
        $ips = $ip !== null
            ? [$ip]
            : array_values(array_filter(array_merge(
                gethostbynamel($host) ?: [],
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            )));

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $resolved) {
            if (! filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }
}

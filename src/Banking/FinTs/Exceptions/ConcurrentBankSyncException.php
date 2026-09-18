<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

/**
 * Raised when a transaction sync cannot start because another sync for the same
 * account is already running or waiting on SCA.
 *
 * Fail closed: do not invent completeness or overlap catch-up ranges.
 */
class ConcurrentBankSyncException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.concurrent_sync');
    }
}

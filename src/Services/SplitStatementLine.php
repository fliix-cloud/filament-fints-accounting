<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Reconciliation;

final class SplitStatementLine
{
    public function __construct(
        private readonly FinalizeReconciliation $finalizer,
        private readonly AccountingAuthorizer $authorizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $allocations
     */
    public function handle(
        BankStatementLine $line,
        array $allocations,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Reconciliation {
        $this->authorizer->authorize('finalize_reconciliation', $line);
        if (count($allocations) < 2) {
            throw new ReconciliationException(__('filament-accounting::errors.split_requires_multiple_allocations'));
        }

        return $this->finalizer->handle($line, $allocations, $reason, $idempotencyKey);
    }
}

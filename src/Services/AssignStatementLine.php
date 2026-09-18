<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Reconciliation;

final class AssignStatementLine
{
    public function __construct(
        private readonly FinalizeReconciliation $finalizer,
        private readonly AccountingAuthorizer $authorizer,
    ) {}

    /**
     * Assign the complete bank statement line to exactly one accounting target.
     *
     * A smaller payment than the selected open item is a partial settlement, not
     * a split. The caller therefore never supplies an amount for this operation.
     *
     * @param  array<string, mixed>  $assignment
     */
    public function handle(
        BankStatementLine $line,
        array $assignment,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): Reconciliation {
        $this->authorizer->authorize('finalize_reconciliation', $line);
        $assignment['amount_minor'] = (int) $line->amount_minor;

        return $this->finalizer->handle($line, [$assignment], $reason, $idempotencyKey);
    }
}

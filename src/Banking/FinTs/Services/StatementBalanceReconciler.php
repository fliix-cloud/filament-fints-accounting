<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Model\StatementOfAccount\Statement;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction;
use FilamentAccounting\Banking\FinTs\Data\StatementBalanceEvidence;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Support\Carbon;

/**
 * Binds FinTS statement and/or account balance information to imported
 * transaction coverage so a successful sync alone is not completeness proof.
 */
final class StatementBalanceReconciler
{
    /**
     * Prefer statement opening/closing balances when the bank provides them.
     * Fall back to a recently synced account booked balance only when the sync
     * window ends on that balance day. Missing bindable evidence is retained as
     * unavailable; arithmetic conflicts fail closed as mismatched.
     */
    public function forStatement(
        AccountingBankAccount $account,
        StatementOfAccount $statement,
        ?\DateTimeInterface $windowFrom = null,
        ?\DateTimeInterface $windowTo = null,
    ): StatementBalanceEvidence {
        $currency = strtoupper((string) ($account->currency ?: 'EUR'));
        $notes = [];
        $days = $statement->getStatements();
        $windowFromStr = $windowFrom ? Carbon::parse($windowFrom)->toDateString() : null;
        $windowToStr = $windowTo ? Carbon::parse($windowTo)->toDateString() : null;

        if ($days === []) {
            return $this->unavailable(
                $account,
                $currency,
                $windowFromStr,
                $windowToStr,
                ['statement_empty'],
            );
        }

        $opening = null;
        $closing = null;
        $statementTxNet = 0;
        $hasOpening = false;
        $hasClosing = false;

        foreach (array_values($days) as $index => $day) {
            if (! $day instanceof Statement) {
                continue;
            }

            $dayOpening = $this->signedStartMinor($day, $currency);
            $dayTxNet = $this->dayTransactionNetMinor($day, $currency);
            $statementTxNet += $dayTxNet;

            $endBalance = $day->getEndBalance();
            $dayClosing = $endBalance === null
                ? $dayOpening + $dayTxNet
                : $this->floatToSignedMinor((float) $endBalance, $currency);

            if ($index === 0) {
                $opening = $dayOpening;
                $hasOpening = true;
            } elseif ($closing !== null && $dayOpening !== $closing) {
                $notes[] = 'statement_day_balance_chain_break';
            }

            $closing = $dayClosing;
            $hasClosing = true;

            if ($endBalance !== null && ($dayOpening + $dayTxNet) !== $dayClosing) {
                $notes[] = 'statement_day_internal_mismatch:'.$day->getDate()->format('Y-m-d');
            }
        }

        $importedNet = $this->importedBookedNetMinor($account, $windowFromStr, $windowToStr);

        if ($statementTxNet !== $importedNet) {
            $notes[] = 'imported_booked_net_differs_from_statement_tx_net';
        }

        $accountBalance = $account->booked_balance_minor;
        $accountBalanceAt = $account->balance_at?->toIso8601String();
        $accountDelta = null;

        if ($hasClosing && $accountBalance !== null && $this->accountBalanceUsableForWindow($account, $windowToStr)) {
            $accountDelta = $closing - $accountBalance;
            if ($accountDelta !== 0) {
                $notes[] = 'account_booked_balance_differs_from_statement_closing';
            }
        } elseif ($accountBalance === null) {
            $notes[] = 'account_booked_balance_absent';
        } else {
            $notes[] = 'account_booked_balance_not_bound_to_window';
        }

        if (! $hasOpening && ! $hasClosing) {
            return $this->unavailable(
                $account,
                $currency,
                $windowFromStr,
                $windowToStr,
                array_merge($notes, ['statement_balances_absent']),
            );
        }

        $delta = null;
        if ($hasOpening && $hasClosing) {
            $delta = ($opening + $statementTxNet) - $closing;
            if ($delta !== 0) {
                $notes[] = 'opening_plus_tx_net_differs_from_closing';
            }
        }

        $mismatched = false;
        foreach ($notes as $note) {
            if (
                $note === 'imported_booked_net_differs_from_statement_tx_net'
                || $note === 'account_booked_balance_differs_from_statement_closing'
                || $note === 'opening_plus_tx_net_differs_from_closing'
                || $note === 'statement_day_balance_chain_break'
                || str_starts_with($note, 'statement_day_internal_mismatch:')
            ) {
                $mismatched = true;
                break;
            }
        }

        return new StatementBalanceEvidence(
            status: $mismatched
                ? StatementBalanceEvidence::STATUS_MISMATCHED
                : StatementBalanceEvidence::STATUS_MATCHED,
            kind: 'statement_balance',
            currency: $currency,
            windowFrom: $windowFromStr,
            windowTo: $windowToStr,
            statementOpeningMinor: $opening,
            statementClosingMinor: $closing,
            statementTxNetMinor: $statementTxNet,
            importedBookedNetMinor: $importedNet,
            accountBookedBalanceMinor: $accountBalance,
            accountBalanceAt: $accountBalanceAt,
            deltaMinor: $accountDelta ?? $delta,
            notes: array_values(array_unique($notes)),
        );
    }

    /**
     * Retain a balance-sync snapshot. Capture alone is not a transaction-window match.
     */
    public function forBalanceSnapshot(AccountingBankAccount $account): StatementBalanceEvidence
    {
        $currency = strtoupper((string) ($account->currency ?: 'EUR'));

        if ($account->booked_balance_minor === null) {
            return new StatementBalanceEvidence(
                status: StatementBalanceEvidence::STATUS_UNAVAILABLE,
                kind: 'balance_snapshot',
                currency: $currency,
                notes: ['booked_balance_missing_after_sync'],
            );
        }

        return new StatementBalanceEvidence(
            status: StatementBalanceEvidence::STATUS_CAPTURED,
            kind: 'balance_snapshot',
            currency: $currency,
            accountBookedBalanceMinor: $account->booked_balance_minor,
            accountBalanceAt: $account->balance_at?->toIso8601String() ?? now()->toIso8601String(),
            notes: ['balance_snapshot_retained_not_yet_bound_to_transaction_window'],
        );
    }

    /**
     * @param  list<string>  $notes
     */
    private function unavailable(
        AccountingBankAccount $account,
        string $currency,
        ?string $windowFrom,
        ?string $windowTo,
        array $notes,
    ): StatementBalanceEvidence {
        return new StatementBalanceEvidence(
            status: StatementBalanceEvidence::STATUS_UNAVAILABLE,
            kind: 'statement_balance',
            currency: $currency,
            windowFrom: $windowFrom,
            windowTo: $windowTo,
            importedBookedNetMinor: $this->importedBookedNetMinor($account, $windowFrom, $windowTo),
            accountBookedBalanceMinor: $account->booked_balance_minor,
            accountBalanceAt: $account->balance_at?->toIso8601String(),
            notes: array_values(array_unique($notes)),
        );
    }

    private function accountBalanceUsableForWindow(AccountingBankAccount $account, ?string $windowTo): bool
    {
        if ($account->booked_balance_minor === null || $account->last_balance_sync_at === null || $windowTo === null) {
            return false;
        }

        $balanceDay = Carbon::parse($account->last_balance_sync_at)->toDateString();

        return $windowTo === $balanceDay || Carbon::parse($windowTo)->isSameDay(Carbon::today());
    }

    private function importedBookedNetMinor(
        AccountingBankAccount $account,
        ?string $windowFrom,
        ?string $windowTo,
    ): int {
        $query = BankStatementLine::query()
            ->where('bank_account_id', $account->id)
            ->where('source_status', StatementLineStatus::Booked);

        if ($windowFrom !== null) {
            $query->whereDate('booking_date', '>=', $windowFrom);
        }
        if ($windowTo !== null) {
            $query->whereDate('booking_date', '<=', $windowTo);
        }

        return (int) $query->sum('amount_minor');
    }

    private function signedStartMinor(Statement $day, string $currency): int
    {
        $minor = abs($this->floatToSignedMinor((float) $day->getStartBalance(), $currency));

        return $day->getCreditDebit() === Statement::CD_DEBIT ? -$minor : $minor;
    }

    private function dayTransactionNetMinor(Statement $day, string $currency): int
    {
        $net = 0;

        foreach ($day->getTransactions() as $transaction) {
            if (! $transaction instanceof Transaction) {
                continue;
            }

            if (! $transaction->getBooked()) {
                continue;
            }

            $amount = abs($this->floatToSignedMinor((float) $transaction->getAmount(), $currency));
            $credit = $transaction->getCreditDebit() === Transaction::CD_CREDIT;
            if ($transaction->isStorno()) {
                $credit = ! $credit;
            }
            $net += $credit ? $amount : -$amount;
        }

        return $net;
    }

    private function floatToSignedMinor(float $amount, string $currency): int
    {
        $sign = $amount < 0.0 ? -1 : 1;
        $absolute = number_format(abs($amount), 2, '.', '');

        return ExactMoney::ofString($absolute, $currency)->minorAmount * $sign;
    }
}

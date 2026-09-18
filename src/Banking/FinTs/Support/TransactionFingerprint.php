<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Fhp\Model\StatementOfAccount\Transaction;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use FilamentAccounting\Support\ExactMoney;

final class TransactionFingerprint
{
    /**
     * Conservative fingerprint: all stable fields from the upstream transaction
     * plus the local account identity. Occurrence discriminates identical-looking
     * legitimate bookings within the same account.
     */
    public static function for(BankAccount $account, Transaction $transaction): string
    {
        $structured = $transaction->getStructuredDescription();
        ksort($structured);

        $payload = implode('|', [
            (string) $account->id,
            $transaction->getBookingDate()?->format('Y-m-d') ?? '',
            $transaction->getValutaDate()?->format('Y-m-d') ?? '',
            (string) ExactMoney::ofString((string) $transaction->getAmount(), $account->currency ?: 'EUR')->minorAmount,
            $transaction->getCreditDebit(),
            $transaction->getBookingCode(),
            $transaction->getBookingText(),
            $transaction->getName(),
            $transaction->getAccountNumber(),
            $transaction->getBankCode(),
            $transaction->getDescription1(),
            $transaction->getDescription2(),
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            // Booked vs pending must not change identity; promotion updates status in place.
            $transaction->isStorno() ? '1' : '0',
            (string) $transaction->getPN(),
        ]);

        return hash('sha256', $payload);
    }
}

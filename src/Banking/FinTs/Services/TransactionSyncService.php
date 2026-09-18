<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Data\StatementBalanceEvidence;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Events\BankTransactionsSynced;
use FilamentAccounting\Banking\FinTs\Exceptions\ConcurrentBankSyncException;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Support\TransactionFingerprint;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TransactionSyncService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly UnifiedBankTransactionImporter $importer,
        private readonly StatementActionFactory $statementActions,
        private readonly StatementBalanceReconciler $balanceReconciler,
    ) {}

    public function sync(
        AccountingBankAccount $account,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?Model $actor = null,
        ?string $returnUrl = null,
    ): ScaOutcome {
        $this->assertUsable($account);
        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
        $to ??= Carbon::today();

        if ($from === null && $account->catch_up_from instanceof \DateTimeInterface) {
            $from = Carbon::parse($account->catch_up_from);
        } elseif ($from === null) {
            $from = $account->last_transaction_sync_at
                ? Carbon::parse($account->last_transaction_sync_at)->subDays((int) config('filament-accounting.banking.fints.sync.incremental_overlap_days', 3))
                : Carbon::today()->subDays((int) config('filament-accounting.banking.fints.sync.initial_lookback_days', 90));
        }
        [$from, $to, $nextFrom] = $this->boundedRange(Carbon::parse($from), Carbon::parse($to));

        $run = $this->claimExclusiveTransactionSync(
            $account,
            $connection,
            Carbon::parse($from),
            Carbon::parse($to),
            $nextFrom ? Carbon::parse($nextFrom) : null,
        );
        $client = $this->factory->make($connection);
        $action = $this->statementActions->create(
            $client,
            $account->toSepaAccount(),
            Carbon::parse($from)->toDateTime(),
            Carbon::parse($to)->toDateTime(),
        );
        $outcome = $this->sca->execute(
            $connection,
            $action,
            ScaOperationType::SyncTransactions,
            $client,
            $run,
            $returnUrl,
            $actor,
        );

        if (! $outcome->isDone()) {
            $run->status = SyncStatus::RequiresAttention;
            $run->save();

            return $outcome;
        }

        $statement = $this->statementActions->result($action);
        $result = $this->importStatementDetailed($account, $statement);
        $evidence = $this->balanceReconciler->forStatement(
            $account,
            $statement,
            $run->from_date,
            $run->to_date,
        );
        $this->markSyncCompleted($account, $run, $result, $evidence);

        return $outcome;
    }

    public function importStatement(AccountingBankAccount $account, StatementOfAccount $statement): int
    {
        return $this->importStatementDetailed($account, $statement)['imported'];
    }

    /** @return array{imported: int, updated: int} */
    public function importStatementDetailed(AccountingBankAccount $account, StatementOfAccount $statement): array
    {
        $this->assertUsable($account);
        $before = BankStatementLine::query()->where('bank_account_id', $account->id)->count();
        $seen = [];
        $rows = [];

        foreach ($statement->getStatements() as $day) {
            foreach ($day->getTransactions() as $transaction) {
                $fingerprint = TransactionFingerprint::for($account, $transaction);
                $occurrence = ($seen[$fingerprint] ?? 0) + 1;
                $seen[$fingerprint] = $occurrence;
                $rows[] = $this->mapTransaction($account, $transaction, $fingerprint, $occurrence);
            }
        }

        $result = $this->importer->import($account, $rows);
        $after = BankStatementLine::query()->where('bank_account_id', $account->id)->count();
        $inserted = max(0, $after - $before);

        return [
            'imported' => $inserted,
            'updated' => max(0, $result->upserted - $inserted),
        ];
    }

    /**
     * @param  array{imported: int, updated: int}  $result
     */
    public function markSyncCompleted(
        AccountingBankAccount $account,
        BankSyncRun $run,
        array $result,
        ?StatementBalanceEvidence $evidence = null,
    ): void {
        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }

        if ($evidence instanceof StatementBalanceEvidence) {
            $run->reconciliation_evidence = $evidence->toArray();
            $run->status = $evidence->isMismatched()
                ? SyncStatus::RequiresAttention
                : SyncStatus::Completed;
            if ($evidence->isMismatched()) {
                $run->error_code = 'statement_balance_mismatch';
                $run->error_message = 'Imported transactions do not align with statement/balance evidence.';
            } elseif ($evidence->isUnavailable()) {
                $run->error_code = 'statement_balance_evidence_unavailable';
                $run->error_message = 'Sync finished without bindable statement/balance completeness evidence.';
            }
        } else {
            $run->status = SyncStatus::Completed;
        }

        $run->item_count = $result['imported'] + $result['updated'];
        $run->finished_at = now();
        $run->save();

        if ($run->requested_from_date instanceof Carbon) {
            $account->catch_up_from = Carbon::parse($run->requested_from_date);
            $account->last_transaction_sync_at = $run->to_date ?? now();
        } else {
            $account->catch_up_from = null;
            $account->last_transaction_sync_at = now();
        }
        $account->save();

        $connection->last_transaction_sync_at = now();
        $connection->save();

        event(new BankTransactionsSynced($connection->id, $account->id, $run->item_count));
    }

    private function assertUsable(AccountingBankAccount $account): void
    {
        if (! $account->isUsable()) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
    }

    /**
     * Finalize an SCA-interrupted transaction sync, then continue catch-up.
     *
     * Never reports complete while catch_up_from remains, or when a follow-up
     * SCA / concurrent competitor stops the drain.
     *
     * @return array{outcome: ScaOutcome, chunks: int, complete: bool, stopped_for: string}
     */
    public function finalizeInterruptedSyncAndContinueCatchUp(
        AccountingBankAccount $account,
        BankSyncRun $run,
        StatementOfAccount $statement,
        ?Model $actor = null,
        ?string $returnUrl = null,
    ): array {
        $result = $this->importStatementDetailed($account, $statement);
        $evidence = $this->balanceReconciler->forStatement(
            $account,
            $statement,
            $run->from_date,
            $run->to_date,
        );
        $this->markSyncCompleted($account, $run, $result, $evidence);
        $account->refresh();

        if (! ($account->catch_up_from instanceof \DateTimeInterface)) {
            return [
                'outcome' => new ScaOutcome(ScaSessionState::Done),
                'chunks' => 0,
                'complete' => true,
                'stopped_for' => 'done',
            ];
        }

        return $this->drainCatchUp($account, $actor, $returnUrl);
    }

    /**
     * @return array{outcome: ScaOutcome, chunks: int, complete: bool, stopped_for: string}
     */
    public function drainCatchUp(
        AccountingBankAccount $account,
        ?Model $actor = null,
        ?string $returnUrl = null,
    ): array {
        $maxChunks = max(1, (int) config('filament-accounting.banking.fints.sync.max_drain_chunks', 20));
        $chunks = 0;
        $outcome = null;

        do {
            try {
                $outcome = $this->sync($account, null, null, $actor, $returnUrl);
            } catch (ConcurrentBankSyncException) {
                return [
                    'outcome' => $outcome ?? new ScaOutcome(ScaSessionState::Done),
                    'chunks' => $chunks,
                    'complete' => false,
                    'stopped_for' => 'concurrent',
                ];
            }
            $chunks++;
            $account->refresh();

            if (! $outcome->isDone()) {
                return [
                    'outcome' => $outcome,
                    'chunks' => $chunks,
                    'complete' => false,
                    'stopped_for' => 'sca',
                ];
            }
        } while ($account->catch_up_from instanceof \DateTimeInterface && $chunks < $maxChunks);

        $complete = ! ($account->catch_up_from instanceof \DateTimeInterface);

        return [
            'outcome' => $outcome,
            'chunks' => $chunks,
            'complete' => $complete,
            'stopped_for' => $complete ? 'done' : 'max_chunks',
        ];
    }

    /**
     * Serialize transaction-sync claims per account so concurrent workers cannot
     * overlap catch-up ranges or race catch_up_from updates.
     */
    private function claimExclusiveTransactionSync(
        AccountingBankAccount $account,
        BankConnection $connection,
        Carbon $from,
        Carbon $to,
        ?Carbon $nextFrom,
    ): BankSyncRun {
        return $account->getConnection()->transaction(function () use ($account, $connection, $from, $to, $nextFrom): BankSyncRun {
            AccountingBankAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Running always blocks. RequiresAttention only blocks while unfinished
            // (SCA wait). Finished mismatch/attention runs must not freeze catch-up.
            $blocking = BankSyncRun::query()
                ->where('accounting_bank_account_id', $account->getKey())
                ->where('type', SyncType::Transactions)
                ->where(function ($query): void {
                    $query->where('status', SyncStatus::Running)
                        ->orWhere(function ($query): void {
                            $query->where('status', SyncStatus::RequiresAttention)
                                ->whereNull('finished_at');
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($blocking) {
                throw new ConcurrentBankSyncException(
                    'A transaction sync is already running or waiting for strong customer authentication on this account.'
                );
            }

            return BankSyncRun::query()->create([
                'bank_connection_id' => $connection->id,
                'accounting_bank_account_id' => $account->id,
                'type' => SyncType::Transactions,
                'status' => SyncStatus::Running,
                'from_date' => $from,
                'to_date' => $to,
                'requested_from_date' => $nextFrom,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * @return array{Carbon, Carbon, ?Carbon}
     */
    public function boundedRange(Carbon $from, Carbon $to): array
    {
        $maxDays = (int) config('filament-accounting.banking.fints.sync.max_range_days', 90);
        if ($from->diffInDays($to) > $maxDays) {
            $chunkTo = $from->copy()->addDays($maxDays);

            return [$from, $chunkTo, $chunkTo->copy()->addDay()];
        }

        return [$from, $to, null];
    }

    private function mapTransaction(
        AccountingBankAccount $account,
        Transaction $transaction,
        string $fingerprint,
        int $occurrence,
    ): BankStatementLineData {
        $money = ExactMoney::ofString((string) $transaction->getAmount(), $account->currency ?: 'EUR');
        $incoming = $transaction->getCreditDebit() === Transaction::CD_CREDIT;
        if ($transaction->isStorno()) {
            $incoming = ! $incoming;
        }
        $amountMinor = $incoming ? abs($money->minorAmount) : -abs($money->minorAmount);
        $structured = $transaction->getStructuredDescription();
        ksort($structured);
        $purpose = $structured['SVWZ'] ?? $transaction->getMainDescription();
        $endToEndId = $transaction->getEndToEndID() ?: ($structured['EREF'] ?? null);
        $counterpartyAccount = $transaction->getAccountNumber();
        $counterpartyIban = Sepa::isValidIban($counterpartyAccount)
            ? Sepa::normalizeIban($counterpartyAccount)
            : null;
        $status = $transaction->isStorno()
            ? 'storno'
            : ($transaction->getBooked() ? 'booked' : 'pending');
        $payload = [
            'fingerprint' => $fingerprint,
            'occurrence' => $occurrence,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($money->currency),
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'booking_date' => $transaction->getBookingDate()?->format('Y-m-d'),
            'value_date' => $transaction->getValutaDate()?->format('Y-m-d'),
            'status' => $status,
            'booking_code' => $transaction->getBookingCode(),
            'booking_text' => $transaction->getBookingText(),
            'counterparty_name' => $transaction->getName(),
            'counterparty_account' => $counterpartyAccount,
            'counterparty_bank_code' => $transaction->getBankCode(),
            'description_1' => $transaction->getDescription1(),
            'description_2' => $transaction->getDescription2(),
            'structured_description' => $structured,
            'purpose' => $purpose,
            'end_to_end_id' => $endToEndId,
            'primanota' => (string) $transaction->getPN(),
            'text_key_addition' => (string) $transaction->getTextKeyAddition(),
        ];

        return new BankStatementLineData(
            externalId: hash('sha256', $fingerprint.'#'.$occurrence),
            amountMinor: $amountMinor,
            currency: $money->currency,
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: $transaction->getBookingDate()?->format('Y-m-d'),
            valueDate: $transaction->getValutaDate()?->format('Y-m-d'),
            sourceStatus: $status,
            counterpartyName: $transaction->getName(),
            counterpartyIban: $counterpartyIban,
            counterpartyAccount: $counterpartyAccount,
            purpose: $purpose,
            endToEndId: $endToEndId,
            paymentReference: $endToEndId,
            sourcePayload: $payload,
        );
    }
}

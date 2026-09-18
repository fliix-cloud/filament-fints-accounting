<?php

namespace FilamentAccounting\Banking\Services;

use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankImportRun;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\BankTransactionSourceVersion;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Builder;

final class UnifiedBankTransactionImporter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CanonicalJson $canonicalJson,
    ) {}

    /**
     * @param  list<BankStatementLineData>  $lines
     */
    public function import(AccountingBankAccount $account, array $lines, ?string $cursor = null): BankFeedImportResult
    {
        if (! $account->is_active) {
            throw new AccountingException(__('filament-accounting::errors.bank_account_inactive'));
        }

        return $account->getConnection()->transaction(function () use ($account, $lines, $cursor): BankFeedImportResult {
            $run = BankImportRun::query()->create([
                'legal_entity_id' => $account->legal_entity_id,
                'bank_account_id' => $account->getKey(),
                'source' => 'fints',
                'upserted_count' => 0,
                'cursor' => $cursor,
                'meta' => ['skipped' => 0],
            ]);
            $upserted = 0;
            $skipped = 0;

            foreach ($lines as $data) {
                if ($data->sourceAccountExternalId && $data->sourceAccountExternalId !== $account->external_account_id) {
                    $skipped++;

                    continue;
                }

                $attributes = $this->attributes($account, $data);
                $existing = $this->findExisting($account, $data, $attributes);

                if (! $existing instanceof BankStatementLine) {
                    $existing = new BankStatementLine;
                    $existing->fill($attributes);
                    $existing->first_imported_at = now();
                    $existing->save();
                    $this->appendSourceVersion($existing, $data, $run);
                    $upserted++;

                    continue;
                }

                $this->appendSourceVersion($existing, $data, $run);
                $posted = $existing->reconciliations()
                    ->where('status', ReconciliationStatus::Posted)
                    ->exists();

                if ($posted && $this->materialChange($existing, $attributes)) {
                    $existing->needs_review = true;
                    $existing->review_reason = [
                        'code' => 'source_changed_after_posting',
                        'previous_hash' => $existing->source_hash,
                        'new_hash' => $attributes['source_hash'],
                    ];
                    $existing->last_imported_at = now();
                    $existing->save();
                    $upserted++;

                    continue;
                }

                $existing->fill($attributes);
                $existing->save();
                $upserted++;
            }

            $run->upserted_count = $upserted;
            $run->meta = ['skipped' => $skipped];
            $run->save();

            $entity = LegalEntity::query()->find($account->legal_entity_id);
            if ($entity instanceof LegalEntity) {
                $this->audit->log($entity, 'bank.transactions-imported', $account, [
                    'upserted' => $upserted,
                    'skipped' => $skipped,
                    'source' => 'fints',
                ]);
            }

            return new BankFeedImportResult($upserted, $skipped, $cursor);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function findExisting(
        AccountingBankAccount $account,
        BankStatementLineData $data,
        array $attributes,
    ): ?BankStatementLine {
        $exact = BankStatementLine::query()
            ->where('legal_entity_id', $account->legal_entity_id)
            ->where('source', 'fints')
            ->where('external_id', $data->externalId)
            ->lockForUpdate()
            ->first();

        if ($exact instanceof BankStatementLine) {
            return $exact;
        }

        $incomingStatus = $attributes['source_status'] ?? null;
        if (! $incomingStatus instanceof StatementLineStatus) {
            return null;
        }

        $priorStatuses = $this->promotableFrom($incomingStatus);
        if ($priorStatuses === []) {
            return null;
        }

        $query = BankStatementLine::query()
            ->where('legal_entity_id', $account->legal_entity_id)
            ->where('bank_account_id', $account->getKey())
            ->where('source', 'fints')
            ->where('amount_minor', $data->amountMinor)
            ->where('currency', strtoupper($data->currency))
            ->whereIn('source_status', $priorStatuses)
            ->lockForUpdate();

        $reference = $this->meaningfulReference($data->endToEndId);
        if ($reference !== null) {
            return $this->single($query->whereRaw('upper(end_to_end_id) = ?', [$reference]));
        }

        $hasIdentity = false;
        foreach (['counterparty_name', 'counterparty_account', 'purpose'] as $field) {
            $value = $this->normalizeIdentity($attributes[$field] ?? null);
            if ($value !== null) {
                $hasIdentity = true;
                $query->whereRaw('upper('.$field.') = ?', [$value]);
            }
        }

        // Amount+currency alone is not a safe pending→booked identity.
        if (! $hasIdentity) {
            return null;
        }

        return $this->single($query);
    }

    /**
     * Cross-external-id matching only promotes earlier statuses (never demotes).
     *
     * @return list<StatementLineStatus>
     */
    private function promotableFrom(StatementLineStatus $incoming): array
    {
        return match ($incoming) {
            StatementLineStatus::Booked => [StatementLineStatus::Pending],
            StatementLineStatus::Storno => [StatementLineStatus::Pending, StatementLineStatus::Booked],
            StatementLineStatus::Pending => [],
        };
    }

    /** @param Builder<BankStatementLine> $query */
    private function single(Builder $query): ?BankStatementLine
    {
        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array<string, mixed> */
    private function attributes(AccountingBankAccount $account, BankStatementLineData $data): array
    {
        $payload = $data->sourcePayload ?? [];
        $canonical = $this->canonicalJson->encode($payload);

        return [
            'legal_entity_id' => $account->legal_entity_id,
            'bank_account_id' => $account->getKey(),
            'source' => 'fints',
            'external_id' => $data->externalId,
            'source_account_external_id' => $data->sourceAccountExternalId ?? $account->external_account_id,
            'amount_minor' => $data->amountMinor,
            'currency' => strtoupper($data->currency),
            'booking_date' => $data->bookingDate,
            'value_date' => $data->valueDate,
            'source_status' => StatementLineStatus::tryFrom($data->sourceStatus) ?? StatementLineStatus::Booked,
            'counterparty_name' => $data->counterpartyName,
            'counterparty_iban' => filled($data->counterpartyIban) ? Sepa::normalizeIban((string) $data->counterpartyIban) : null,
            'counterparty_account' => $data->counterpartyAccount,
            'purpose' => $data->purpose,
            'end_to_end_id' => $data->endToEndId,
            'payment_reference' => $data->paymentReference,
            'source_payload' => $payload,
            'source_hash' => $data->sourceHash ?: hash('sha256', $canonical),
            'source_created_at' => $data->sourceCreatedAt,
            'source_updated_at' => $data->sourceUpdatedAt,
            'last_imported_at' => now(),
        ];
    }

    private function appendSourceVersion(
        BankStatementLine $transaction,
        BankStatementLineData $data,
        BankImportRun $run,
    ): void {
        $payload = $data->sourcePayload ?? [];
        $canonical = $this->canonicalJson->encode($payload);
        $hash = $data->sourceHash ?: hash('sha256', $canonical);
        $latest = $transaction->sourceVersions()->lockForUpdate()->latest('version')->first();

        if ($latest instanceof BankTransactionSourceVersion && hash_equals($latest->source_hash, $hash)) {
            return;
        }

        $latestVersion = $latest instanceof BankTransactionSourceVersion ? $latest->version : 0;

        $transaction->sourceVersions()->create([
            'legal_entity_id' => $transaction->legal_entity_id,
            'import_run_id' => $run->getKey(),
            'version' => $latestVersion + 1,
            'source_id' => $data->externalId,
            'source_fingerprint' => (string) ($payload['fingerprint'] ?? $data->externalId),
            'source_status' => $data->sourceStatus,
            'normalized_payload' => json_decode($canonical, true, flags: JSON_THROW_ON_ERROR),
            'raw_payload' => is_string($payload['raw'] ?? null) ? $payload['raw'] : null,
            'source_hash' => $hash,
            'recorded_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function materialChange(BankStatementLine $existing, array $attributes): bool
    {
        return $existing->amount_minor !== $attributes['amount_minor']
            || strtoupper((string) $existing->currency) !== $attributes['currency']
            || $existing->source_status !== $attributes['source_status']
            || $existing->external_id !== $attributes['external_id'];
    }

    private function meaningfulReference(?string $value): ?string
    {
        $reference = $this->normalizeIdentity($value);

        return $reference === null || $reference === 'NOTPROVIDED' ? null : $reference;
    }

    private function normalizeIdentity(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}

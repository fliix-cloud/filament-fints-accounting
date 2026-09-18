<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use JsonException;

final class JournalIntegrityVerifier
{
    public function __construct(private readonly JournalSnapshot $snapshots) {}

    /** Validate one journal at a time without retaining the company's complete ledger. */
    public function assertValid(int $legalEntityId): void
    {
        foreach (JournalEntry::query()->where('legal_entity_id', $legalEntityId)->with('lines')->lazyById(1) as $entry) {
            $events = AuditEvent::query()->where('legal_entity_id', $legalEntityId)->where('operation', 'journal.posted')
                ->where('target_type', $entry->getMorphClass())->where('target_id', (string) $entry->getKey())->limit(2)->get();
            $result = $this->check(new Collection([$entry->getKey() => $entry]), $events);
            if ($result['issues'] !== []) {
                throw new AuditEvidenceException('Journal integrity failed: '.$result['issues'][0]['code']);
            }
        }
        foreach (AuditEvent::query()->where('legal_entity_id', $legalEntityId)->where('operation', 'journal.posted')->lazyById(100) as $event) {
            if ($event->target_type !== (new JournalEntry)->getMorphClass()
                || ! JournalEntry::query()->where('legal_entity_id', $legalEntityId)->whereKey($event->target_id)->exists()) {
                throw new AuditEvidenceException('Journal evidence target missing.');
            }
        }
    }

    /**
     * The caller holds the entity lock while verifying the ledger, chain and anchors.
     * Returned entries are the exact in-memory records that were checked.
     *
     * @return array{entries: Collection<int, JournalEntry>, posted_entry_count: int, issues: list<array<string, mixed>>}
     */
    public function verify(int $legalEntityId): array
    {
        $entries = JournalEntry::query()->where('legal_entity_id', $legalEntityId)
            ->with('lines')->orderBy('id')->get()->keyBy('id');
        $events = AuditEvent::query()->where('legal_entity_id', $legalEntityId)
            ->where('operation', 'journal.posted')->orderBy('sequence')->get();

        return $this->check($entries, $events);
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @param  Collection<int, AuditEvent>  $events
     * @return array{entries: Collection<int, JournalEntry>, posted_entry_count: int, issues: list<array<string, mixed>>}
     */
    private function check(Collection $entries, Collection $events): array
    {
        $targetType = (new JournalEntry)->getMorphClass();
        $byTarget = $events->where('target_type', $targetType)->groupBy('target_id');
        $issues = [];
        $postedCount = 0;

        foreach ($entries as $entry) {
            if ($entry->getRawOriginal('status') !== JournalStatus::Posted->value) {
                continue;
            }
            $postedCount++;
            if ($entry->lines->count() < 2) {
                $issues[] = $this->issue('journal_too_few_lines', 'Posted journal has fewer than two lines.', $entry);
            }
            if ((int) $entry->lines->sum('base_debit_minor') !== (int) $entry->lines->sum('base_credit_minor')) {
                $issues[] = $this->issue('journal_unbalanced', 'Posted journal is not balanced in base currency.', $entry);
            }
            if (! $this->hasHistoricalValues($entry)) {
                $issues[] = $this->issue('journal_history_missing', 'Posted journal lacks complete historical account or period values.', $entry);
            }
            $evidenceCount = $byTarget->get($entry->getKey())?->count() ?? 0;
            if ($evidenceCount !== 1) {
                $issues[] = $this->issue(
                    $evidenceCount === 0 ? 'journal_evidence_missing' : 'journal_evidence_duplicate',
                    'Each posted journal must have exactly one journal.posted event.',
                    $entry,
                );
            }
        }

        // Walk evidence in the reverse direction as well: deleted/moved/downgraded
        // journals must not disappear from verification merely by leaving the posted query.
        foreach ($events as $event) {
            $entry = $entries->get(is_numeric($event->target_id) ? (int) $event->target_id : $event->target_id);
            if ($event->target_type !== $targetType || ! $entry instanceof JournalEntry) {
                $issues[] = $this->issue('journal_target_missing', 'Journal evidence no longer resolves to a journal in this entity.', null, $event);

                continue;
            }
            if ($entry->getRawOriginal('status') !== JournalStatus::Posted->value) {
                $issues[] = $this->issue('journal_status_changed', 'Journal evidence refers to a record that is no longer posted.', $entry, $event);
            }
            $snapshot = $event->payload['journal_snapshot'] ?? null;
            $hash = $event->payload['snapshot_sha256'] ?? null;
            if (! is_array($snapshot) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $issues[] = $this->issue('journal_snapshot_missing', 'Posting evidence has no valid snapshot and SHA-256 digest.', $entry, $event);

                continue;
            }
            if (($snapshot['schema_version'] ?? null) !== JournalSnapshot::VERSION) {
                $issues[] = $this->issue('journal_snapshot_version_unsupported', 'Posting snapshot schema is not supported.', $entry, $event);

                continue;
            }
            try {
                if (! hash_equals($hash, $this->snapshots->hash($snapshot))) {
                    $issues[] = $this->issue('journal_snapshot_hash_mismatch', 'Posting evidence does not match its own digest.', $entry, $event);
                }
                if (! hash_equals($hash, $this->snapshots->hash($this->snapshots->capture($entry)))) {
                    $issues[] = $this->issue('journal_snapshot_mismatch', 'Persisted journal content differs from its posting evidence.', $entry, $event);
                }
            } catch (InvalidArgumentException|JsonException) {
                $issues[] = $this->issue('journal_snapshot_invalid', 'Posting snapshot contains values that cannot be canonicalized.', $entry, $event);
            }
        }

        return ['entries' => $entries, 'posted_entry_count' => $postedCount, 'issues' => $issues];
    }

    private function hasHistoricalValues(JournalEntry $entry): bool
    {
        $period = $entry->period_snapshot;
        if (! is_array($period) || ($period['id'] ?? null) !== (int) $entry->period_id
            || ! is_int($period['fiscal_year'] ?? null) || ! is_int($period['period_number'] ?? null)
            || ! is_string($period['uuid'] ?? null) || ! is_string($period['starts_on'] ?? null)
            || ! is_string($period['ends_on'] ?? null)) {
            return false;
        }

        return $entry->lines->every(function (JournalLine $line): bool {
            $account = $line->account_snapshot;

            return is_array($account) && ($account['id'] ?? null) === (int) $line->ledger_account_id
                && is_string($account['uuid'] ?? null) && is_string($account['code'] ?? null)
                && is_string($account['name'] ?? null) && is_string($account['type'] ?? null)
                && is_string($account['normal_balance'] ?? null) && array_key_exists('currency', $account);
        });
    }

    /** @return array<string, mixed> */
    private function issue(string $code, string $message, ?JournalEntry $entry = null, ?AuditEvent $event = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'journal_uuid' => $entry?->uuid,
            'audit_event_uuid' => $event?->uuid,
        ];
    }
}

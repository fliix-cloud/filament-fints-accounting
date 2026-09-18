<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\Settlement;

/**
 * Frozen settlement history: open-item / document / party identity at the moment
 * of settlement, independent of later master-data edits.
 */
final class SettlementEvidence
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function capture(
        OpenItem $item,
        int $amountMinor,
        string $currency,
        ?BankStatementLine $line = null,
        ?int $remainingBeforeMinor = null,
    ): array {
        $item->loadMissing(['document', 'party']);
        $document = $item->document;
        $party = $item->party;

        $remainingBefore = $remainingBeforeMinor ?? $item->remainingMinor();
        $remainingAfter = $remainingBefore >= 0
            ? $remainingBefore - abs($amountMinor)
            : $remainingBefore + abs($amountMinor);

        return [
            'schema_version' => self::VERSION,
            'captured_at' => now()->toIso8601String(),
            'settlement' => [
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ],
            'open_item' => [
                'id' => (int) $item->getKey(),
                'uuid' => (string) $item->uuid,
                'kind' => $item->kind->value,
                'currency' => (string) $item->currency,
                'original_minor' => (int) $item->original_minor,
                'remaining_before_minor' => $remainingBefore,
                'remaining_after_minor' => $remainingAfter,
                'due_on' => $item->due_on?->toDateString(),
                'is_reversed' => (bool) $item->is_reversed,
            ],
            'document' => $document instanceof Document ? [
                'id' => (int) $document->getKey(),
                'uuid' => (string) $document->uuid,
                'number' => $document->number,
                'type' => $document->type->value,
                'direction' => $document->direction->value,
                'invoice_version' => (int) $document->invoice_version,
                'gross_minor' => (int) $document->gross_minor,
                'currency' => (string) $document->currency,
                'party_snapshot' => $document->party_snapshot,
            ] : null,
            'party' => $party instanceof Party ? [
                'id' => (int) $party->getKey(),
                'uuid' => (string) $party->uuid,
                'legal_name' => (string) $party->legal_name,
                'display_name' => $party->display_name,
                'external_reference' => $party->external_reference,
            ] : null,
            'statement_line' => $line instanceof BankStatementLine ? [
                'id' => (int) $line->getKey(),
                'uuid' => (string) $line->uuid,
                'external_id' => $line->external_id,
                'amount_minor' => (int) $line->amount_minor,
                'currency' => (string) $line->currency,
                'booking_date' => $line->booking_date?->toDateString(),
                'value_date' => $line->value_date?->toDateString(),
                'source_hash' => $line->source_hash,
                'purpose' => $line->purpose,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forReversal(Settlement $original, int $reversalAmountMinor): array
    {
        $base = is_array($original->evidence) ? $original->evidence : [];

        return [
            'schema_version' => self::VERSION,
            'captured_at' => now()->toIso8601String(),
            'reverses_settlement_id' => (int) $original->getKey(),
            'reverses_settlement_uuid' => (string) $original->uuid,
            'settlement' => [
                'amount_minor' => $reversalAmountMinor,
                'currency' => (string) $original->currency,
            ],
            'original_evidence' => $base,
        ];
    }
}

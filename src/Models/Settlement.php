<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $open_item_id
 * @property int $journal_entry_id
 * @property int $amount_minor
 * @property string $currency
 * @property bool $is_reversed
 * @property int|null $reverses_id
 * @property array<string, mixed>|null $evidence
 * @property-read Reconciliation|null $reconciliation
 */
class Settlement extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_settlements';

    protected $fillable = [
        'legal_entity_id',
        'open_item_id',
        'journal_entry_id',
        'amount_minor',
        'currency',
        'is_reversed',
        'reverses_id',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'is_reversed' => 'boolean',
            'evidence' => 'array',
        ];
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reconciliation(): HasOne
    {
        return $this->hasOne(Reconciliation::class, 'journal_entry_id', 'journal_entry_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}

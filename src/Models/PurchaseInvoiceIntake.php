<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $identity
 * @property string $disk
 * @property array<string, array{filename: string, path: string, sha256: string, size: int}> $files
 * @property array<string, bool> $preserved_files
 * @property string $status
 * @property string|null $last_error
 * @property int|null $document_id
 * @property Carbon|null $preserved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseInvoiceIntake extends AccountingModel
{
    use HasUuid;

    protected $table = 'accounting_purchase_invoice_intakes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array', 'preserved_files' => 'array', 'preserved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $intake): void {
            $stored = self::query()->findOrFail($intake->getRawOriginal($intake->getKeyName()));
            if ($intake->isDirty(['id', 'uuid', 'legal_entity_id', 'identity', 'disk', 'files', 'created_by_type', 'created_by_id'])) {
                throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
            }
            foreach ($stored->preserved_files as $role => $preserved) {
                if ($preserved && ! ($intake->preserved_files[$role] ?? false)) {
                    throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
                }
            }
            if (($stored->document_id !== null && $intake->isDirty('document_id'))
                || ($stored->preserved_at !== null && $intake->isDirty('preserved_at'))) {
                throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
            }
        });
        static::deleting(function (): void {
            throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
        });
    }
}

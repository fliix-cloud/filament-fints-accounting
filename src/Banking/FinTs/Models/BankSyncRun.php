<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $bank_connection_id
 * @property int $legal_entity_id
 * @property int|null $accounting_bank_account_id
 * @property SyncType $type
 * @property SyncStatus $status
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property Carbon|null $requested_from_date
 * @property int $item_count
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $reconciliation_evidence
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class BankSyncRun extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_sync_runs';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->legal_entity_id ??= BankConnection::query()
                ->whereKey($model->bank_connection_id)
                ->value('legal_entity_id');
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => SyncType::class,
            'status' => SyncStatus::class,
            'from_date' => 'date',
            'to_date' => 'date',
            'requested_from_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reconciliation_evidence' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingBankAccount::class, 'accounting_bank_account_id');
    }
}

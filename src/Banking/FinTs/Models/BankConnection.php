<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $display_name
 * @property string $bank_code
 * @property string $endpoint_url
 * @property string $username
 * @property string $pin
 * @property string|null $customer_id
 * @property string|null $tan_mode_id
 * @property string|null $tan_mode_name
 * @property string|null $tan_medium_name
 * @property string|null $encrypted_fints_state
 * @property Carbon|null $fints_state_saved_at
 * @property array<int, array<string, mixed>>|null $tan_modes_cache
 * @property array<string, mixed>|null $capabilities
 * @property BankConnectionStatus $status
 * @property Carbon|null $last_successful_connection_at
 * @property Carbon|null $last_account_sync_at
 * @property Carbon|null $last_transaction_sync_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 */
class BankConnection extends Model
{
    use UsesPackageConnection;

    public const AUTHENTICATION_FAILED = 'authentication_failed';

    protected $table = 'fints_bank_connections';

    protected $hidden = [
        'username',
        'pin',
        'customer_id',
        'encrypted_fints_state',
    ];

    protected $fillable = [
        'legal_entity_id',
        'display_name',
        'bank_code',
        'endpoint_url',
        'username',
        'pin',
        'customer_id',
        'tan_mode_id',
        'tan_mode_name',
        'tan_medium_name',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->legal_entity_id ??= app(LegalEntityScope::class)->require()->getKey();
            $model->uuid ??= (string) Str::uuid();
            $model->status ??= BankConnectionStatus::Pending;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'pin' => 'encrypted',
            'customer_id' => 'encrypted',
            'encrypted_fints_state' => 'encrypted',
            'fints_state_saved_at' => 'datetime',
            'status' => BankConnectionStatus::class,
            'capabilities' => 'array',
            'tan_modes_cache' => 'array',
            'last_successful_connection_at' => 'datetime',
            'last_account_sync_at' => 'datetime',
            'last_transaction_sync_at' => 'datetime',
        ];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(AccountingBankAccount::class, 'bank_connection_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(BankTransfer::class, 'bank_connection_id');
    }

    public function directDebits(): HasMany
    {
        return $this->hasMany(BankDirectDebit::class, 'bank_connection_id');
    }

    public function scaSessions(): HasMany
    {
        return $this->hasMany(StrongAuthenticationSession::class, 'bank_connection_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(BankSyncRun::class, 'bank_connection_id');
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasPin(): bool
    {
        return filled($this->getRawOriginal('pin'));
    }

    /**
     * Banks lock the PIN after a few failed logins. Once a login was rejected,
     * only an explicit connection test may contact the bank again.
     */
    public function blocksAutomaticLogin(): bool
    {
        return $this->status === BankConnectionStatus::Disabled
            || ($this->status === BankConnectionStatus::Error
                && $this->last_error_code === self::AUTHENTICATION_FAILED);
    }

    public function markAuthenticationFailed(string $message): void
    {
        $this->status = BankConnectionStatus::Error;
        $this->last_error_code = self::AUTHENTICATION_FAILED;
        $this->last_error_message = $message;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    public function tanModeChoices(): array
    {
        $options = [];

        foreach ($this->tan_modes_cache ?? [] as $mode) {
            $id = (string) ($mode['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $options[$id] = (string) ($mode['name'] ?? $id);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function tanMediaChoices(?string $modeId = null): array
    {
        $mode = $this->tanModeFromCache($modeId);
        if ($mode === null) {
            return [];
        }

        $options = [];
        foreach ($mode['media'] ?? [] as $medium) {
            if (! is_array($medium)) {
                continue;
            }
            $name = (string) ($medium['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $label = $name;
            if (filled($medium['phone'] ?? null)) {
                $label .= ' ('.$medium['phone'].')';
            }
            $options[$name] = $label;
        }

        return $options;
    }

    public function tanModeNeedsMedium(?string $modeId = null): bool
    {
        $mode = $this->tanModeFromCache($modeId);

        return (bool) ($mode['needs_medium'] ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tanModeFromCache(?string $modeId = null): ?array
    {
        $modeId ??= (string) ($this->tan_mode_id ?? '');
        if ($modeId === '') {
            return null;
        }

        foreach ($this->tan_modes_cache ?? [] as $mode) {
            if ((string) ($mode['id'] ?? '') === $modeId) {
                return $mode;
            }
        }

        return null;
    }
}

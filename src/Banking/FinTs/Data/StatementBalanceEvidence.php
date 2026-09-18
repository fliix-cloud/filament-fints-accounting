<?php

namespace FilamentAccounting\Banking\FinTs\Data;

/**
 * Retained proof that a FinTS transaction sync was checked against statement
 * and/or account balance information. A green sync without matched evidence is
 * not completeness proof.
 */
final readonly class StatementBalanceEvidence
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_MISMATCHED = 'mismatched';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_CAPTURED = 'captured';

    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $status,
        public string $kind,
        public string $currency,
        public ?string $windowFrom = null,
        public ?string $windowTo = null,
        public ?int $statementOpeningMinor = null,
        public ?int $statementClosingMinor = null,
        public ?int $statementTxNetMinor = null,
        public ?int $importedBookedNetMinor = null,
        public ?int $accountBookedBalanceMinor = null,
        public ?string $accountBalanceAt = null,
        public ?int $deltaMinor = null,
        public array $notes = [],
    ) {}

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    public function isMismatched(): bool
    {
        return $this->status === self::STATUS_MISMATCHED;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::STATUS_UNAVAILABLE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'kind' => $this->kind,
            'currency' => $this->currency,
            'window_from' => $this->windowFrom,
            'window_to' => $this->windowTo,
            'statement_opening_minor' => $this->statementOpeningMinor,
            'statement_closing_minor' => $this->statementClosingMinor,
            'statement_tx_net_minor' => $this->statementTxNetMinor,
            'imported_booked_net_minor' => $this->importedBookedNetMinor,
            'account_booked_balance_minor' => $this->accountBookedBalanceMinor,
            'account_balance_at' => $this->accountBalanceAt,
            'delta_minor' => $this->deltaMinor,
            'notes' => array_values($this->notes),
        ];
    }
}

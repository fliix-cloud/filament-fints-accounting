<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Model\TanMedium;
use Fhp\Model\TanMode;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Events\BankConnectionTested;
use FilamentAccounting\Banking\FinTs\Exceptions\AuthenticationException;
use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Support\BankQuirks;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use Illuminate\Database\Eloquent\Model;

class BankConnectionService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly CapabilityService $capabilities,
    ) {}

    public function test(BankConnection $connection, ?Model $actor = null, ?string $returnUrl = null): ScaOutcome
    {
        $connection->endpoint_url = EndpointValidator::validate((string) $connection->endpoint_url);
        $connection->bank_code = BankQuirks::normalizeBankCode((string) $connection->bank_code, $connection->endpoint_url);
        $connection->last_error_message = null;
        $connection->last_error_code = null;
        $connection->save();

        try {
            $this->assertTanSelection($connection);
            $client = $this->factory->make($connection);

            if (! $client->hasOpenDialog()) {
                $login = $client->login();
                $outcome = $this->sca->evaluate($connection, $login, ScaOperationType::TestConnection, $client, $connection, $returnUrl, $actor);

                if (! $outcome->isDone()) {
                    if ($outcome->requiresUser()) {
                        $connection->status = BankConnectionStatus::NeedsAttention;
                        $connection->save();
                    }

                    return $outcome;
                }
            }

            $this->markSuccessful($connection, $client);
            event(new BankConnectionTested($connection->id, true));

            return new ScaOutcome(ScaSessionState::Done);
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e);
            if ($mapped instanceof AuthenticationException) {
                $connection->markAuthenticationFailed($mapped->userMessage());
            } else {
                $connection->status = BankConnectionStatus::Error;
                $connection->last_error_message = $mapped->userMessage();
                $connection->save();
            }
            event(new BankConnectionTested($connection->id, false));
            throw $mapped;
        }
    }

    public function markSuccessful(BankConnection $connection, ?FintsClient $client = null): BankConnection
    {
        // After an interactive SCA completion the coordinator has already saved
        // the FinTS dialog on the connection. Rehydrating it lets us read the BPD
        // without making optimistic capability assumptions.
        if ($client === null) {
            try {
                $client = $this->factory->make($connection);
            } catch (\Throwable) {
                $client = null;
            }
        }

        if ($client instanceof FintsClient) {
            $this->captureAuthenticatedState($connection, $client);
            app(FintsDialogStore::class)->remember($connection, $client);
        }

        $connection->status = BankConnectionStatus::Active;
        $connection->last_error_message = null;
        $connection->last_error_code = null;
        $connection->last_successful_connection_at = now();
        $connection->save();

        return $connection->fresh() ?? $connection;
    }

    public function discoverTanModes(BankConnection $connection): array
    {
        $client = $this->factory->make($connection);

        try {
            $modes = $this->readTanModes($client);
            $connection->tan_modes_cache = $modes;
            $this->syncSelectedTanFromCache($connection, $modes);
            $connection->save();

            return $modes;
        } finally {
            app(FintsDialogStore::class)->remember($connection, $client);
        }
    }

    public function captureAuthenticatedState(BankConnection $connection, FintsClient $client): void
    {
        try {
            // Never call getTanMedia() here. phpFinTS ends the current dialog
            // before HKTAB, which would drop a just-persisted trusted login.
            $modes = $this->mergeTanMediaFromCache($connection, $this->readTanModes($client, includeMedia: false));
            $connection->tan_modes_cache = $modes;
            $this->syncSelectedTanFromCache($connection, $modes);
            $connection->save();
        } catch (\Throwable) {
            // TAN discovery is optional after a successful login.
        }

        try {
            $this->capabilities->discover($connection, $client);
        } catch (\Throwable) {
            // A missing/unreadable BPD must never become an optimistic `true`.
            // CapabilityService defaults every unknown operation to unsupported.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTanModes(FintsClient $client, bool $includeMedia = true): array
    {
        $modes = [];
        foreach ($client->getTanModes() as $mode) {
            $modes[] = $this->serializeTanMode($mode, $client, $includeMedia);
        }

        return $modes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $modes
     * @return array<int, array<string, mixed>>
     */
    private function mergeTanMediaFromCache(BankConnection $connection, array $modes): array
    {
        $cached = [];
        foreach ($connection->tan_modes_cache ?? [] as $mode) {
            if (is_array($mode) && filled($mode['id'] ?? null)) {
                $cached[(string) $mode['id']] = $mode;
            }
        }

        foreach ($modes as $index => $mode) {
            $id = (string) ($mode['id'] ?? '');
            $previous = $cached[$id] ?? null;
            if (! is_array($previous)) {
                continue;
            }
            if (($mode['media'] ?? []) === [] && ($previous['media'] ?? []) !== []) {
                $modes[$index]['media'] = $previous['media'];
            }
        }

        return $modes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $modes
     */
    private function syncSelectedTanFromCache(BankConnection $connection, array $modes): void
    {
        $ids = array_map(fn (array $mode): string => (string) ($mode['id'] ?? ''), $modes);

        if (filled($connection->tan_mode_id) && ! in_array((string) $connection->tan_mode_id, $ids, true)) {
            $connection->tan_mode_id = null;
            $connection->tan_mode_name = null;
            $connection->tan_medium_name = null;
        }

        if (! filled($connection->tan_mode_id) && count($modes) === 1) {
            $connection->tan_mode_id = (string) ($modes[0]['id'] ?? '');
            $connection->tan_mode_name = $modes[0]['name'] ?? null;
        }

        $selected = $connection->tanModeFromCache();
        if ($selected === null) {
            return;
        }

        $connection->tan_mode_name = $selected['name'] ?? $connection->tan_mode_name;

        if (! ($selected['needs_medium'] ?? false)) {
            $connection->tan_medium_name = null;

            return;
        }

        $media = $selected['media'] ?? [];
        $names = [];
        foreach ($media as $medium) {
            if (is_array($medium) && filled($medium['name'] ?? null)) {
                $names[] = (string) $medium['name'];
            }
        }

        if (filled($connection->tan_medium_name) && ! in_array($connection->tan_medium_name, $names, true)) {
            $connection->tan_medium_name = null;
        }

        if (! filled($connection->tan_medium_name) && count($names) === 1) {
            $connection->tan_medium_name = $names[0];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTanMode(TanMode $mode, FintsClient $client, bool $includeMedia = true): array
    {
        $media = [];
        if ($includeMedia && $mode->needsTanMedium()) {
            try {
                foreach ($client->getTanMedia($mode) as $medium) {
                    if (! $medium instanceof TanMedium) {
                        continue;
                    }
                    $media[] = [
                        'name' => $medium->getName(),
                        'phone' => $medium->getPhoneNumber(),
                    ];
                }
            } catch (\Throwable) {
                // Some banks advertise media but do not enumerate them.
            }
        }

        return [
            'id' => $mode->getId(),
            'name' => $mode->getName(),
            'decoupled' => $mode->isDecoupled(),
            'needs_medium' => $mode->needsTanMedium(),
            'media' => $media,
        ];
    }

    private function assertTanSelection(BankConnection $connection): void
    {
        if (BankQuirks::isIngDiba((string) $connection->bank_code)) {
            return;
        }

        if (! filled($connection->tan_mode_id)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_mode_required'));
        }

        if ($connection->tanModeNeedsMedium() && ! filled($connection->tan_medium_name)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/errors.tan_medium_required'));
        }
    }
}

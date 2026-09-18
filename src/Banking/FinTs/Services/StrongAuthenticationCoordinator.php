<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\GetBalance;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\SendSEPADirectDebit;
use Fhp\Action\SendSEPARealtimeTransfer;
use Fhp\Action\SendSEPATransfer;
use Fhp\BaseAction;
use Fhp\Model\FlickerTan\SvgRenderer;
use Fhp\Model\FlickerTan\TanRequestChallengeFlicker;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Model\TanRequestChallengeImage;
use Fhp\Protocol\DialogInitialization;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\ChallengeType;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Enums\VopMatchType;
use FilamentAccounting\Banking\FinTs\Events\BankAccountsSynced;
use FilamentAccounting\Banking\FinTs\Events\BankBalancesSynced;
use FilamentAccounting\Banking\FinTs\Events\BankDirectDebitFailed;
use FilamentAccounting\Banking\FinTs\Events\BankDirectDebitSubmitted;
use FilamentAccounting\Banking\FinTs\Events\BankTransferFailed;
use FilamentAccounting\Banking\FinTs\Events\BankTransferSubmitted;
use FilamentAccounting\Banking\FinTs\Events\StrongAuthenticationCompleted;
use FilamentAccounting\Banking\FinTs\Events\StrongAuthenticationExpired;
use FilamentAccounting\Banking\FinTs\Events\StrongAuthenticationStarted;
use FilamentAccounting\Banking\FinTs\Exceptions\AmbiguousSubmissionException;
use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;
use FilamentAccounting\Banking\FinTs\Exceptions\ScaPollingLimitException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use FilamentAccounting\Banking\FinTs\Support\SerializedFintsPayload;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class StrongAuthenticationCoordinator
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly FintsDialogStore $dialogs,
        private readonly StatementActionFactory $statementActions,
    ) {}

    public function execute(
        BankConnection $connection,
        BaseAction $action,
        ScaOperationType $type,
        FintsClient $client,
        ?Model $related = null,
        ?string $returnUrl = null,
        ?Model $actor = null,
    ): ScaOutcome {
        if (! $action instanceof DialogInitialization && ! $client->hasOpenDialog()) {
            $login = $client->login();
            if ($this->actionNeedsUser($login)) {
                return $this->evaluate($connection, $login, $type, $client, $related, $returnUrl, $actor);
            }
        }

        try {
            $client->execute($action);
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e);
            if (! $this->shouldRelogin($mapped)) {
                throw $mapped;
            }

            $client->forgetDialog();
            $login = $client->login();
            if ($this->actionNeedsUser($login)) {
                return $this->evaluate($connection, $login, $type, $client, $related, $returnUrl, $actor);
            }
            $client->execute($action);
        }

        return $this->evaluate($connection, $action, $type, $client, $related, $returnUrl, $actor);
    }

    public function submitTan(string $sessionUuid, string $tan, BankConnection $connection, ?Model $actor = null): ScaOutcome
    {
        return $this->mutateOpenSession(
            $sessionUuid,
            $connection,
            function (StrongAuthenticationSession $session) use ($tan, $connection, $actor): ScaOutcome {
                [$client, $action] = $this->restore($session, $connection);

                try {
                    $client->submitTan($action, $tan);
                } finally {
                    unset($tan);
                }

                return $this->evaluate(
                    $connection,
                    $action,
                    $session->operation_type,
                    $client,
                    $this->related($session),
                    $session->return_url,
                    $actor,
                    $session,
                );
            },
            submissionMayBeAmbiguous: true,
        );
    }

    public function checkDecoupled(string $sessionUuid, BankConnection $connection, ?Model $actor = null): ScaOutcome
    {
        return $this->mutateOpenSession(
            $sessionUuid,
            $connection,
            function (StrongAuthenticationSession $session) use ($connection, $actor): ScaOutcome {
                $this->assertPollAllowed($session);
                [$client, $action] = $this->restore($session, $connection);
                $client->checkDecoupledSubmission($action);
                $this->persistClientState($session, $client, $action);

                return $this->evaluate(
                    $connection,
                    $action,
                    $session->operation_type,
                    $client,
                    $this->related($session),
                    $session->return_url,
                    $actor,
                    $session,
                );
            },
        );
    }

    public function confirmVop(string $sessionUuid, BankConnection $connection, ?Model $actor = null): ScaOutcome
    {
        return $this->mutateOpenSession(
            $sessionUuid,
            $connection,
            function (StrongAuthenticationSession $session) use ($connection, $actor): ScaOutcome {
                [$client, $action] = $this->restore($session, $connection);
                $client->confirmVop($action);

                return $this->evaluate(
                    $connection,
                    $action,
                    $session->operation_type,
                    $client,
                    $this->related($session),
                    $session->return_url,
                    $actor,
                    $session,
                );
            },
            submissionMayBeAmbiguous: true,
        );
    }

    public function poll(string $sessionUuid, BankConnection $connection, ?Model $actor = null): ScaOutcome
    {
        return $this->mutateOpenSession(
            $sessionUuid,
            $connection,
            function (StrongAuthenticationSession $session) use ($connection, $actor): ScaOutcome {
                $this->assertPollAllowed($session);
                [$client, $action] = $this->restore($session, $connection);
                $client->pollAction($action);
                $this->persistClientState($session, $client, $action);

                return $this->evaluate(
                    $connection,
                    $action,
                    $session->operation_type,
                    $client,
                    $this->related($session),
                    $session->return_url,
                    $actor,
                    $session,
                );
            },
        );
    }

    public function expireIfNeeded(StrongAuthenticationSession $session): void
    {
        if ($session->expires_at !== null && $session->expires_at->isPast() && $session->state->isOpen()) {
            $session->state = ScaSessionState::Expired;
            $session->clearSensitiveState();
            $this->syncRelatedStatus($session, PaymentStatus::Expired);
            event(new StrongAuthenticationExpired($session->uuid, $session->bank_connection_id));
        }
    }

    public function evaluate(
        BankConnection $connection,
        BaseAction $action,
        ScaOperationType $type,
        FintsClient $client,
        ?Model $related = null,
        ?string $returnUrl = null,
        ?Model $actor = null,
        ?StrongAuthenticationSession $session = null,
    ): ScaOutcome {
        if ($action->needsVopConfirmation()) {
            $session = $this->persistInteractive($connection, $action, $type, $client, ScaSessionState::NeedsVop, $related, $returnUrl, $actor, $session);
            $this->syncRelatedStatus($session, PaymentStatus::AwaitingVop);

            return new ScaOutcome(ScaSessionState::NeedsVop, $session, $action);
        }

        if ($action->needsPollingWait()) {
            $session = $this->persistInteractive($connection, $action, $type, $client, ScaSessionState::NeedsPolling, $related, $returnUrl, $actor, $session);
            $this->syncRelatedStatus($session, PaymentStatus::WaitingBank);

            return new ScaOutcome(ScaSessionState::NeedsPolling, $session, $action);
        }

        if ($action->needsTan()) {
            $decoupled = $client->isDecoupledSelected();
            $state = $decoupled ? ScaSessionState::NeedsDecoupled : ScaSessionState::NeedsTan;
            $session = $this->persistInteractive($connection, $action, $type, $client, $state, $related, $returnUrl, $actor, $session);
            $this->syncRelatedStatus($session, $decoupled ? PaymentStatus::AwaitingDecoupledConfirmation : PaymentStatus::AwaitingTan);

            return new ScaOutcome($state, $session, $action);
        }

        if ($action->isDone()) {
            if ($action instanceof DialogInitialization && $session instanceof StrongAuthenticationSession) {
                $followUp = $this->followUpAction($type, $related, $client);
                if ($followUp !== null) {
                    $client->execute($followUp);

                    return $this->evaluate($connection, $followUp, $type, $client, $related, $returnUrl, $actor, $session);
                }
            }

            $followUp = $this->finishBusiness($type, $action, $connection, $related, $session, $actor, $returnUrl);
            $this->markConnectionSuccessful($connection);

            if ($session) {
                $session->state = ScaSessionState::Done;
                $session->last_status_message = $action->successMessage;
                $session->clearSensitiveState();
                event(new StrongAuthenticationCompleted($session->uuid, $session->bank_connection_id));
            }

            $this->dialogs->remember($connection, $client);

            // SyncTransactions may continue catch-up and open a fresh SCA session.
            if ($followUp instanceof ScaOutcome && ! $followUp->isDone()) {
                return $followUp;
            }

            return new ScaOutcome(ScaSessionState::Done, $session, $action, $action->successMessage);
        }

        throw ErrorMapper::map(new \RuntimeException('FinTS action ended in an unknown state.'));
    }

    private function markConnectionSuccessful(BankConnection $connection): void
    {
        $connection->status = BankConnectionStatus::Active;
        $connection->last_error_message = null;
        $connection->last_error_code = null;
        $connection->last_successful_connection_at = now();
        $connection->save();
    }

    private function actionNeedsUser(BaseAction $action): bool
    {
        return $action->needsTan() || $action->needsVopConfirmation() || $action->needsPollingWait();
    }

    private function shouldRelogin(\Throwable $e): bool
    {
        if ($e instanceof ScaExpiredException) {
            return true;
        }

        return $e instanceof FintsValidationException
            && $e->userMessage() === __('filament-accounting::banking/fints/errors.login_required');
    }

    private function followUpAction(ScaOperationType $type, ?Model $related, FintsClient $client): ?BaseAction
    {
        return match ($type) {
            ScaOperationType::SyncAccounts => GetSEPAAccounts::create(),
            ScaOperationType::SyncBalances => $this->balanceFollowUp($related),
            ScaOperationType::SyncTransactions => $this->statementFollowUp($related, $client),
            ScaOperationType::Transfer => $this->transferFollowUp($related),
            ScaOperationType::DirectDebit => $this->directDebitFollowUp($related),
            default => null,
        };
    }

    private function balanceFollowUp(?Model $related): ?BaseAction
    {
        $account = $related instanceof BankAccount
            ? $related
            : ($related instanceof BankSyncRun ? $related->account : null);

        return $account instanceof BankAccount
            ? GetBalance::create($account->toSepaAccount())
            : null;
    }

    private function statementFollowUp(?Model $related, FintsClient $client): ?BaseAction
    {
        if (! $related instanceof BankSyncRun) {
            return null;
        }

        $account = $related->account;
        if (! $account instanceof BankAccount) {
            return null;
        }

        $to = $related->to_date ? Carbon::parse($related->to_date) : Carbon::today();
        $from = $related->from_date ? Carbon::parse($related->from_date) : $to->copy()->subDays(90);

        return $this->statementActions->create(
            $client,
            $account->toSepaAccount(),
            $from->toDateTime(),
            $to->toDateTime(),
        );
    }

    private function transferFollowUp(?Model $related): BaseAction
    {
        if (! $related instanceof BankTransfer) {
            throw new FintsValidationException('The resumed transfer no longer exists.');
        }

        $account = $related->account;
        if (! $account instanceof BankAccount) {
            throw new FintsValidationException('The source account for the resumed transfer no longer exists.');
        }

        $xml = app(SepaXmlService::class)->transferXml($related, $account);

        return $related->type === TransferType::Realtime
            ? SendSEPARealtimeTransfer::create($account->toSepaAccount(), $xml, false)
            : SendSEPATransfer::create($account->toSepaAccount(), $xml);
    }

    private function directDebitFollowUp(?Model $related): BaseAction
    {
        if (! $related instanceof BankDirectDebit) {
            throw new FintsValidationException('The resumed direct debit no longer exists.');
        }

        $account = $related->account;
        if (! $account instanceof BankAccount) {
            throw new FintsValidationException('The source account for the resumed direct debit no longer exists.');
        }

        $xml = app(SepaXmlService::class)->directDebitXml($related, $account);

        return SendSEPADirectDebit::create($account->toSepaAccount(), $xml);
    }

    private function finishBusiness(ScaOperationType $type, BaseAction $action, BankConnection $connection, ?Model $related, ?StrongAuthenticationSession $session = null, ?Model $actor = null, ?string $returnUrl = null): ?ScaOutcome
    {
        if ($type === ScaOperationType::Transfer && $related instanceof BankTransfer) {
            $action->ensureDone();

            if ($related->status !== PaymentStatus::Submitted) {
                $related->status = PaymentStatus::Submitted;
                $related->submitted_at = now();
                $related->bank_status_text = $action->successMessage;
                $related->error_code = null;
                $related->error_message = null;
                $related->save();
                event(new BankTransferSubmitted($related->uuid, $connection->id));
            }

            return null;
        }

        if ($type === ScaOperationType::DirectDebit && $related instanceof BankDirectDebit) {
            $action->ensureDone();

            if ($related->status !== PaymentStatus::Submitted) {
                $related->status = PaymentStatus::Submitted;
                $related->submitted_at = now();
                $related->bank_status_text = $action->successMessage;
                $related->error_code = null;
                $related->error_message = null;
                $related->save();
                event(new BankDirectDebitSubmitted($related->uuid, $connection->id));
            }

            return null;
        }

        if ($type === ScaOperationType::SyncAccounts && $action instanceof GetSEPAAccounts) {
            app(AccountSyncService::class)->persistAccounts($connection, $action->getAccounts());
            $connection->last_account_sync_at = now();
            $connection->save();
            $count = $connection->accounts()->count();
            $this->completeSyncRun($related, $count);
            event(new BankAccountsSynced($connection->id, $count));

            return null;
        }

        if ($type === ScaOperationType::SyncBalances && $action instanceof GetBalance) {
            $account = $related instanceof BankAccount
                ? $related
                : ($related instanceof BankSyncRun ? $related->account : null);
            if ($account instanceof BankAccount) {
                app(BalanceSyncService::class)->applyResults($account, $action);
                event(new BankBalancesSynced($connection->id, $account->id));
            }
            $this->completeSyncRun($related, 1);

            return null;
        }

        if ($type === ScaOperationType::SyncTransactions && $this->statementActions->supports($action)) {
            $account = $related instanceof BankSyncRun ? $related->account : null;
            if ($account instanceof BankAccount && $related instanceof BankSyncRun) {
                $sync = app(TransactionSyncService::class);
                $continued = $sync->finalizeInterruptedSyncAndContinueCatchUp(
                    $account,
                    $related,
                    $this->statementActions->result($action),
                    $actor ?? $this->actorFromSession($session),
                    $returnUrl ?? $session?->return_url,
                );

                return $continued['outcome'] instanceof ScaOutcome ? $continued['outcome'] : null;
            }
        }

        return null;
    }

    private function actorFromSession(?StrongAuthenticationSession $session): ?Model
    {
        if ($session === null || blank($session->confirmed_by_type) || blank($session->confirmed_by_id)) {
            return null;
        }

        $class = Relation::getMorphedModel((string) $session->confirmed_by_type)
            ?? (string) $session->confirmed_by_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($session->confirmed_by_id);
    }

    private function completeSyncRun(?Model $related, int $count): void
    {
        if (! $related instanceof BankSyncRun) {
            return;
        }

        $related->status = SyncStatus::Completed;
        $related->item_count = $count;
        $related->finished_at = now();
        $related->save();
    }

    /**
     * @return array{0: FintsClient, 1: BaseAction}
     */
    private function restore(StrongAuthenticationSession $session, BankConnection $connection): array
    {
        if ($session->encrypted_fints_state === null || $session->encrypted_action === null) {
            throw new ScaExpiredException('The strong authentication session no longer contains resumable state.');
        }

        $persisted = base64_decode((string) $session->encrypted_fints_state, true);
        $serialized = base64_decode((string) $session->encrypted_action, true);

        if ($persisted === false || $serialized === false) {
            throw new ScaExpiredException('Stored FinTS state could not be decoded.');
        }

        $client = $this->factory->make($connection, $persisted);
        $action = SerializedFintsPayload::unserialize($serialized, requireObject: true);

        if (! $action instanceof BaseAction) {
            throw new ScaExpiredException('Stored FinTS action is invalid.');
        }

        return [$client, $action];
    }

    private function persistInteractive(
        BankConnection $connection,
        BaseAction $action,
        ScaOperationType $type,
        FintsClient $client,
        ScaSessionState $state,
        ?Model $related,
        ?string $returnUrl,
        ?Model $actor,
        ?StrongAuthenticationSession $session,
    ): StrongAuthenticationSession {
        $session ??= new StrongAuthenticationSession;
        $session->bank_connection_id = $connection->id;
        $session->operation_type = $type;
        $session->state = $state;
        $session->related_type = $related?->getMorphClass();
        $session->related_id = $related?->getKey();
        $session->return_url = $returnUrl;
        $session->expires_at ??= now()->addMinutes((int) config('filament-accounting.banking.fints.security.sca_ttl_minutes', 30));
        $this->applyChallenge($session, $action, $client, $connection);
        $this->persistClientState($session, $client, $action);

        if ($actor) {
            $session->confirmed_by_type = $actor->getMorphClass();
            $session->confirmed_by_id = (string) $actor->getKey();
        }

        $session->save();
        event(new StrongAuthenticationStarted($session->uuid, $connection->id, $state->value));

        return $session;
    }

    private function persistClientState(StrongAuthenticationSession $session, FintsClient $client, BaseAction $action): void
    {
        $session->encrypted_fints_state = base64_encode($client->persist());

        if (! $action->isDone()) {
            $session->encrypted_action = base64_encode(serialize($action));
        }

        $session->save();
    }

    private function applyChallenge(StrongAuthenticationSession $session, BaseAction $action, FintsClient $client, BankConnection $connection): void
    {
        if ($action->needsVopConfirmation()) {
            $vop = $action->getVopConfirmationRequest();
            $session->vop_information = $vop?->getInformationForUser();
            $session->vop_match = VopMatchType::fromBankResult($vop?->getVerificationResult());
            $session->challenge_type = ChallengeType::Text;
        }

        if ($action->needsPollingWait()) {
            $info = $action->getPollingInfo();
            $delay = max((int) ($info?->getNextAttemptInSeconds() ?? 2), (int) config('filament-accounting.banking.fints.security.min_poll_seconds', 2));
            $session->poll_interval_seconds = $delay;
            $session->next_poll_at = now()->addSeconds($delay);
            $session->first_poll_at ??= now();
            $session->poll_attempts++;
            $session->last_status_message = $info?->getInformationForUser();
        }

        if ($action->needsTan()) {
            $request = $action->getTanRequest();
            $session->encrypted_challenge_text = $request?->getChallenge();
            $session->tan_medium_name = $request?->getTanMediumName();
            $session->challenge_type = ChallengeType::Text;

            if ($client->isDecoupledSelected()) {
                $session->challenge_type = ChallengeType::Decoupled;
                $delay = (int) config('filament-accounting.banking.fints.security.min_poll_seconds', 2);
                try {
                    if ($client instanceof PhpFintsClient) {
                        $mode = $client->unwrap()->getSelectedTanMode();
                        if ($mode && ! $mode instanceof NoPsd2TanMode && $mode->isDecoupled()) {
                            $delay = max($delay, $session->poll_attempts === 0
                                ? $mode->getFirstDecoupledCheckDelaySeconds()
                                : $mode->getPeriodicDecoupledCheckDelaySeconds());
                            $session->max_poll_attempts = $mode->getMaxDecoupledChecks() ?: null;
                        }
                    }
                } catch (\Throwable) {
                }
                $session->poll_interval_seconds = $delay;
                $session->next_poll_at = now()->addSeconds($delay);
                $session->first_poll_at ??= now();
            }

            $payload = $request?->getChallengeHhdUc();
            if ($payload !== null) {
                try {
                    $flicker = new TanRequestChallengeFlicker($payload);
                    $svg = new SvgRenderer($flicker->getFlickerPattern());
                    $session->challenge_type = ChallengeType::Flicker;
                    $session->encrypted_challenge_payload = base64_encode($svg->getImage());
                    $session->challenge_mime = 'image/svg+xml';
                } catch (InvalidArgumentException) {
                    $image = new TanRequestChallengeImage($payload);
                    $session->challenge_type = ChallengeType::Image;
                    $session->encrypted_challenge_payload = base64_encode($image->getData());
                    $session->challenge_mime = $image->getMimeType();
                }
            }
        }
    }

    private function lockOpenSession(string $uuid, BankConnection $connection): StrongAuthenticationSession
    {
        /** @var StrongAuthenticationSession $session */
        $session = StrongAuthenticationSession::query()
            ->where('uuid', $uuid)
            ->where('bank_connection_id', $connection->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($session->expires_at !== null && $session->expires_at->isPast() && $session->state->isOpen()) {
            throw new ScaExpiredException('The strong authentication session has expired.');
        }

        if (! $session->state->isOpen()) {
            throw new ScaExpiredException;
        }

        return $session;
    }

    private function assertPollAllowed(StrongAuthenticationSession $session): void
    {
        if ($session->next_poll_at && $session->next_poll_at->isFuture()) {
            throw new \RuntimeException('Polling is not yet permitted by the bank.');
        }

        if ($session->max_poll_attempts && $session->poll_attempts >= $session->max_poll_attempts) {
            throw new ScaPollingLimitException('Maximum decoupled checks exceeded.');
        }
    }

    private function related(StrongAuthenticationSession $session): ?Model
    {
        if (! $session->related_type || ! $session->related_id) {
            return null;
        }

        $class = Relation::getMorphedModel($session->related_type) ?? $session->related_type;
        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($session->related_id);
    }

    private function syncRelatedStatus(StrongAuthenticationSession $session, PaymentStatus $status): void
    {
        $related = $this->related($session);

        if ($related instanceof BankTransfer || $related instanceof BankDirectDebit) {
            $related->status = $status;
            $related->save();
        }
    }

    /**
     * Keep row-lock rollback semantics for SCA concurrency, but persist terminal
     * states in a second transaction after an exception has rolled the mutation
     * back. This prevents expired/ambiguous sessions from becoming open again.
     *
     * @param  callable(StrongAuthenticationSession): ScaOutcome  $callback
     */
    private function mutateOpenSession(
        string $sessionUuid,
        BankConnection $connection,
        callable $callback,
        bool $submissionMayBeAmbiguous = false,
    ): ScaOutcome {
        try {
            return $connection->getConnection()->transaction(function () use ($sessionUuid, $connection, $callback): ScaOutcome {
                $session = $this->lockOpenSession($sessionUuid, $connection);

                return $callback($session);
            });
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e);

            if ($mapped instanceof ScaPollingLimitException) {
                $this->persistTerminalSession($sessionUuid, $connection, ScaSessionState::Failed, PaymentStatus::Expired);
                throw $mapped;
            }

            if ($mapped instanceof ScaExpiredException) {
                $this->persistTerminalSession($sessionUuid, $connection, ScaSessionState::Expired, PaymentStatus::Expired);
                throw $mapped;
            }

            if ($submissionMayBeAmbiguous && $mapped instanceof AmbiguousSubmissionException) {
                $this->persistTerminalSession($sessionUuid, $connection, ScaSessionState::Failed, PaymentStatus::Ambiguous, $mapped->userMessage());
                throw $mapped;
            }

            throw $mapped;
        }
    }

    private function persistTerminalSession(
        string $sessionUuid,
        BankConnection $connection,
        ScaSessionState $state,
        PaymentStatus $relatedStatus,
        ?string $message = null,
    ): void {
        $session = $connection->getConnection()->transaction(function () use ($sessionUuid, $connection, $state, $relatedStatus, $message): ?StrongAuthenticationSession {
            /** @var StrongAuthenticationSession|null $session */
            $session = StrongAuthenticationSession::query()
                ->where('uuid', $sessionUuid)
                ->where('bank_connection_id', $connection->id)
                ->lockForUpdate()
                ->first();

            if (! $session instanceof StrongAuthenticationSession || ! $session->state->isOpen()) {
                return $session;
            }

            $session->state = $state;
            $session->last_status_message = $message ?? $session->last_status_message;
            $session->clearSensitiveState();
            $this->syncRelatedStatus($session, $relatedStatus);

            return $session;
        });

        if (! $session instanceof StrongAuthenticationSession) {
            return;
        }

        if ($state === ScaSessionState::Expired) {
            event(new StrongAuthenticationExpired($session->uuid, $session->bank_connection_id));
        }

        if ($relatedStatus === PaymentStatus::Ambiguous) {
            $related = $this->related($session);
            if ($related instanceof BankTransfer) {
                event(new BankTransferFailed($related->uuid, $connection->id, $message ?? __('filament-accounting::banking/fints/errors.ambiguous')));
            } elseif ($related instanceof BankDirectDebit) {
                event(new BankDirectDebitFailed($related->uuid, $connection->id, $message ?? __('filament-accounting::banking/fints/errors.ambiguous')));
            }
        }
    }
}

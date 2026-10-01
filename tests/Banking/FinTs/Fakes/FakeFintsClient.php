<?php

namespace FilamentAccounting\Tests\Banking\FinTs\Fakes;

use Fhp\BaseAction;
use Fhp\Model\TanMedium;
use Fhp\Model\TanMode;
use Fhp\Model\VopConfirmationRequestImpl;
use Fhp\Model\VopPollingInfo;
use Fhp\Model\VopVerificationResult;
use Fhp\Syntax\Bin;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\ProvidesCamtStatementSchemas;
use FilamentAccounting\Banking\FinTs\Data\PersistedFintsState;

final class FakeFintsClient implements FintsClient, ProvidesCamtStatementSchemas
{
    /** @var list<string> */
    private array $script;

    private int $index = 0;

    public int $executeCalls = 0;

    public int $submitTanCalls = 0;

    public int $checkDecoupledCalls = 0;

    public int $pollCalls = 0;

    public int $confirmVopCalls = 0;

    public bool $openDialog = false;

    public int $loginCalls = 0;

    public ?\Throwable $loginFailure = null;

    public ?string $lastPersistedInstance = null;

    /** @var list<string> */
    public array $camtStatementSchemas = [];

    /** @param list<string> $script */
    public function __construct(array $script = ['done'])
    {
        $this->script = $script === [] ? ['done'] : array_values($script);
    }

    public function login(): BaseAction
    {
        $this->loginCalls++;
        if ($this->loginFailure instanceof \Throwable) {
            throw $this->loginFailure;
        }

        $this->openDialog = true;
        $action = new FakeAction;
        $action->markDone('login-ok');

        return $action;
    }

    public function execute(BaseAction $action): void
    {
        $this->executeCalls++;
        $this->applyCurrent($action);
    }

    public function submitTan(BaseAction $action, string $tan): void
    {
        $this->submitTanCalls++;
        unset($tan);
        $this->advance();
        $this->applyCurrent($action);
    }

    public function checkDecoupledSubmission(BaseAction $action): bool
    {
        $this->checkDecoupledCalls++;
        $this->advance();
        $this->applyCurrent($action);

        return $this->currentStep() === 'done';
    }

    public function pollAction(BaseAction $action): void
    {
        $this->pollCalls++;
        $this->advance();
        $this->applyCurrent($action);
    }

    public function confirmVop(BaseAction $action): void
    {
        $this->confirmVopCalls++;
        $this->advance();
        $this->applyCurrent($action);
    }

    public function getTanModes(): array
    {
        return [];
    }

    public function getTanMedia(TanMode|int $tanMode): array
    {
        return [];
    }

    public function selectTanMode(TanMode|int $tanMode, TanMedium|string|null $tanMedium = null): void {}

    public function supportedParameterSegments(): array
    {
        return [];
    }

    public function advertisedRequestTypes(): array
    {
        return [];
    }

    public function supportedSepaPainSchemas(): array
    {
        return [];
    }

    public function supportedCamtStatementSchemas(): array
    {
        return $this->camtStatementSchemas;
    }

    public function persist(bool $minimal = false): string
    {
        return 'fake-fints-state:'.$this->index;
    }

    public function close(): void
    {
        $this->openDialog = false;
    }

    public function forgetDialog(): void
    {
        $this->openDialog = false;
    }

    public function hasOpenDialog(): bool
    {
        return $this->openDialog;
    }

    public function snapshot(): PersistedFintsState
    {
        return new PersistedFintsState($this->persist());
    }

    public function isDecoupledSelected(): bool
    {
        return $this->currentStep() === 'needsDecoupled';
    }

    public function rememberPersisted(?string $persistedInstance): void
    {
        $this->lastPersistedInstance = $persistedInstance;
    }

    private function currentStep(): string
    {
        return $this->script[$this->index] ?? 'done';
    }

    private function advance(): void
    {
        $this->index++;
    }

    private function applyCurrent(BaseAction $action): void
    {
        $action->setTanRequest(null);
        $action->setPollingInfo(null);
        $action->setVopConfirmationRequest(null);

        if ($action instanceof FakeAction) {
            $action->resetInteractive();
        }

        match ($this->currentStep()) {
            'needsTan', 'needsDecoupled' => $action->setTanRequest(new FakeTanRequest),
            'needsVop' => $action->setVopConfirmationRequest(new VopConfirmationRequestImpl(
                new Bin('vop-id'),
                null,
                'Confirm the payee name',
                VopVerificationResult::CompletedCloseMatch,
                null,
            )),
            'needsPolling' => $action->setPollingInfo(new VopPollingInfo('token', null, 5)),
            default => $action instanceof FakeAction ? $action->markDone() : null,
        };
    }
}

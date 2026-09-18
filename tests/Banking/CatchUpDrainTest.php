<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class CatchUpDrainTest extends TestCase
{
    #[Test]
    public function drain_catch_up_repeats_sync_until_the_marker_clears(): void
    {
        $account = Mockery::mock(AccountingBankAccount::class)->makePartial();
        $account->catch_up_from = Carbon::today()->subDays(200);
        $account->shouldReceive('refresh')->andReturnUsing(function () use ($account): AccountingBankAccount {
            return $account;
        });

        $calls = 0;
        $svc = Mockery::mock(TransactionSyncService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('sync')->andReturnUsing(function () use (&$calls, $account): ScaOutcome {
            $calls++;
            if ($calls >= 3) {
                $account->catch_up_from = null;
            } else {
                $account->catch_up_from = Carbon::today()->subDays(200 - (90 * $calls));
            }

            return new ScaOutcome(ScaSessionState::Done);
        });

        $result = $svc->drainCatchUp($account);

        $this->assertSame(3, $calls);
        $this->assertSame(3, $result['chunks']);
        $this->assertTrue($result['complete']);
        $this->assertSame('done', $result['stopped_for']);
    }

    #[Test]
    public function drain_catch_up_stops_when_sca_is_required(): void
    {
        $account = Mockery::mock(AccountingBankAccount::class)->makePartial();
        $account->catch_up_from = Carbon::today()->subDays(200);
        $account->shouldReceive('refresh')->andReturnSelf();

        $svc = Mockery::mock(TransactionSyncService::class)->makePartial();
        $svc->shouldReceive('sync')->once()->andReturn(new ScaOutcome(ScaSessionState::NeedsTan));

        $result = $svc->drainCatchUp($account);

        $this->assertSame(1, $result['chunks']);
        $this->assertFalse($result['complete']);
        $this->assertSame('sca', $result['stopped_for']);
    }

    #[Test]
    public function drain_catch_up_respects_the_chunk_budget(): void
    {
        config()->set('filament-accounting.banking.fints.sync.max_drain_chunks', 2);

        $account = Mockery::mock(AccountingBankAccount::class)->makePartial();
        $account->catch_up_from = Carbon::today()->subDays(500);
        $account->shouldReceive('refresh')->andReturnSelf();

        $svc = Mockery::mock(TransactionSyncService::class)->makePartial();
        $svc->shouldReceive('sync')->twice()->andReturn(new ScaOutcome(ScaSessionState::Done));

        $result = $svc->drainCatchUp($account);

        $this->assertSame(2, $result['chunks']);
        $this->assertFalse($result['complete']);
        $this->assertSame('max_chunks', $result['stopped_for']);
    }
}

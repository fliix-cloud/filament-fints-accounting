from pathlib import Path
p = Path("tests/Banking/ConcurrentCatchUpSyncTest.php")
text = p.read_text(encoding="utf-8")
start = text.index("    #[Test]\n    public function finalize_interrupted_sync_continues_catch_up_without_false_completeness()")
end = text.index("    private function invokeClaim(")
replacement = '''    #[Test]
    public function drain_catch_up_stops_fail_closed_on_concurrent_claim_without_claiming_completeness(): void
    {
        $account = Mockery::mock(AccountingBankAccount::class)->makePartial();
        $account->catch_up_from = Carbon::today()->subDays(120);
        $account->shouldReceive('refresh')->andReturnSelf();

        $svc = Mockery::mock(TransactionSyncService::class)->makePartial();
        $svc->shouldReceive('sync')
            ->once()
            ->andThrow(new ConcurrentBankSyncException('blocked'));

        $result = $svc->drainCatchUp($account);

        $this->assertFalse($result['complete']);
        $this->assertSame('concurrent', $result['stopped_for']);
        $this->assertSame(0, $result['chunks']);
    }

'''
# Try both naming styles for method
if start < 0:
    start = text.index("    #[Test]\n    public function finalize_interrupted_sync_continues_catch_up_without_false_completeness(): void")
p.write_text(text[:start] + replacement + text[end:], encoding="utf-8", newline="\n")
print("replaced")

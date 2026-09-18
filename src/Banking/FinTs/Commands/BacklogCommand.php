<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\BankingBacklogService;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Console\Command;

class BacklogCommand extends Command
{
    protected $signature = 'filament-accounting:banking-backlog
        {--legal-entity= : Legal entity UUID}
        {--json : Emit machine-readable JSON}
        {--continue : Drain catch-up for every account with an open marker}
        {--ack-run= : Sync run UUID to acknowledge (seen; does not invent completeness)}
        {--ack-note= : Optional note stored with --ack-run}';

    protected $description = 'List open banking sync/intake backlog; fail closed when unresolved work remains';

    public function handle(BankingBacklogService $backlog): int
    {
        try {
            $legalEntityId = $this->resolveLegalEntityId();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($ackUuid = $this->option('ack-run')) {
            $run = BankSyncRun::query()
                ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
                ->where('uuid', $ackUuid)
                ->first();

            if (! $run instanceof BankSyncRun) {
                $this->error("Sync run {$ackUuid} was not found.");

                return self::FAILURE;
            }

            try {
                $backlog->acknowledgeSyncRun($run, $this->option('ack-note') ?: null);
                $this->info("Acknowledged sync run {$ackUuid}.");
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('continue')) {
            $results = $backlog->continueCatchUp($legalEntityId);
            if ($results === []) {
                $this->info('No catch-up markers to continue.');
            }
            foreach ($results as $result) {
                if ($result['complete']) {
                    $this->info(sprintf(
                        'Account %d: catch-up drained in %d chunk(s).',
                        $result['account_id'],
                        $result['chunks'],
                    ));
                } else {
                    $this->warn(sprintf(
                        'Account %d: catch-up still open after %d chunk(s) (stopped_for=%s).',
                        $result['account_id'],
                        $result['chunks'],
                        $result['stopped_for'],
                    ));
                }
            }
        }

        $summary = $backlog->summarize($legalEntityId);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Catch-up accounts', (string) $summary['catch_up_accounts']],
                    ['Open sync runs', (string) $summary['open_sync_runs']],
                    ['Pending intakes', (string) $summary['pending_intakes']],
                    ['Statement lines needing review', (string) $summary['needs_review_lines']],
                ],
            );

            if ($summary['items'] !== []) {
                $this->newLine();
                $this->warn('Open backlog items (fail closed — do not treat green sync as completeness):');
                foreach (array_slice($summary['items'], 0, 50) as $item) {
                    $this->line('  - '.json_encode($item, JSON_UNESCAPED_SLASHES));
                }
                if (count($summary['items']) > 50) {
                    $this->line(sprintf('  … and %d more (use --json for the full list).', count($summary['items']) - 50));
                }
            } else {
                $this->info('No open banking sync/intake backlog.');
            }
        }

        return $summary['has_open_backlog'] ? self::FAILURE : self::SUCCESS;
    }

    private function resolveLegalEntityId(): ?int
    {
        $uuid = $this->option('legal-entity');
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $entity = LegalEntity::query()->where('uuid', $uuid)->first();
        if (! $entity instanceof LegalEntity) {
            throw new \RuntimeException("Legal entity {$uuid} was not found.");
        }

        return (int) $entity->getKey();
    }
}

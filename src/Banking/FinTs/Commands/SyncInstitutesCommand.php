<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Services\InstituteDirectoryService;
use Illuminate\Console\Command;

class SyncInstitutesCommand extends Command
{
    protected $signature = 'filament-accounting:sync-institutes
        {--url= : Override the institute directory URL}
        {--include-without-endpoint : Also store banks that have no PIN/TAN URL}';

    protected $description = 'Import German FinTS/HBCI institute details (BLZ, name, BIC, PIN/TAN URL)';

    public function handle(InstituteDirectoryService $directory): int
    {
        $url = $this->option('url');
        $url = is_string($url) && $url !== '' ? $url : null;

        $result = $directory->sync(
            $url,
            (bool) $this->option('include-without-endpoint'),
        );

        $this->info("Imported {$result['imported']} institutes ({$result['with_pin_tan']} with PIN/TAN URL, {$result['skipped']} skipped).");

        // The directory decides where users send their PIN; make every change visible.
        foreach ($result['changed'] as $change) {
            $this->warn("Endpoint changed for {$change['bank_code']}: {$change['from']} -> {$change['to']}");
        }

        if ($result['rejected'] !== []) {
            $this->warn(count($result['rejected']).' invalid endpoint(s) ignored: '.implode(', ', $result['rejected']));
        }

        return self::SUCCESS;
    }
}

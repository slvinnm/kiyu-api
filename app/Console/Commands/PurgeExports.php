<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use Illuminate\Console\Command;

class PurgeExports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exports:purge {--days=7 : Delete exports older than this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete generated report exports older than the retention window.';

    /**
     * Execute the console command.
     */
    public function handle(ExportService $exports): int
    {
        $days = max(1, (int) $this->option('days'));

        $removed = $exports->purgeOlderThan($days);

        $this->info("Removed {$removed} export file(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

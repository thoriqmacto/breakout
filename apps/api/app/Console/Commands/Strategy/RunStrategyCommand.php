<?php

namespace App\Console\Commands\Strategy;

use App\Models\Strategy;
use App\Models\StrategyRun;
use App\Services\Strategy\StrategyRunner;
use Illuminate\Console\Command;

/**
 * Runs a rule-builder strategy from the CLI, synchronously.
 *
 * The API dispatches RunStrategyJob instead; this is for cron and for running
 * a scan on a box with no queue worker.
 */
class RunStrategyCommand extends Command
{
    protected $signature = 'strategy:run
        {--id=* : Strategy id to run (repeatable). Defaults to every active strategy.}
        {--date= : Scan date (YYYY-MM-DD). Defaults to the latest features_daily date.}';

    protected $description = 'Evaluate rule-builder strategies and persist their matches.';

    public function handle(StrategyRunner $runner): int
    {
        $date = (string) ($this->option('date') ?: $runner->latestScanDate());

        if ($date === '') {
            $this->error('No features_daily rows exist, so there is no date to scan. Run features:extract first.');

            return self::FAILURE;
        }

        $ids = array_filter((array) $this->option('id'));

        $strategies = Strategy::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [], fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();

        if ($strategies->isEmpty()) {
            $this->warn('No matching strategies.');

            return self::SUCCESS;
        }

        $this->line("Scan date: {$date}");

        foreach ($strategies as $strategy) {
            $run = StrategyRun::create([
                'strategy_id' => $strategy->id,
                'scan_date' => $date,
                'status' => StrategyRun::STATUS_QUEUED,
            ]);

            $run = $runner->run($strategy, $run);

            if ($run->status === StrategyRun::STATUS_FAILED) {
                $this->error("  #{$strategy->id} {$strategy->name}: failed -- {$run->error}");

                continue;
            }

            $this->info(sprintf(
                '  #%d %s: %d/%d matched.',
                $strategy->id,
                $strategy->name,
                $run->matched_count,
                $run->evaluated_count,
            ));
        }

        return self::SUCCESS;
    }
}

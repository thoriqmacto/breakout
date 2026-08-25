<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Models\StrategyRun;
use App\Services\Strategy\StrategyRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one strategy in the background.
 *
 * A scan walks every symbol with features for the date, so it is dispatched
 * rather than run inside the request that asked for it.
 */
class RunStrategyJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt only: StrategyRunner already records failures on the run
     * row, so a retry would re-run a scan that has reported its outcome.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $strategyId,
        public readonly int $runId,
    ) {}

    public function handle(StrategyRunner $runner): void
    {
        $strategy = Strategy::find($this->strategyId);
        $run = StrategyRun::find($this->runId);

        if ($strategy === null || $run === null) {
            return;
        }

        $runner->run($strategy, $run);
    }

    /**
     * Reached when the job itself dies (timeout, worker restart) rather than
     * when the scan reports an error, so the run is not left showing "queued"
     * forever.
     */
    public function failed(?\Throwable $e): void
    {
        $run = StrategyRun::find($this->runId);

        if ($run === null || in_array($run->status, [
            StrategyRun::STATUS_COMPLETED,
            StrategyRun::STATUS_FAILED,
        ], true)) {
            return;
        }

        $run->forceFill([
            'status' => StrategyRun::STATUS_FAILED,
            'error' => $e === null ? 'Job failed.' : mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ])->save();

        Strategy::whereKey($this->strategyId)->update([
            'last_run_status' => StrategyRun::STATUS_FAILED,
            'last_run_at' => now(),
        ]);
    }
}

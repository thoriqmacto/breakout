<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Automation\ScheduledTaskRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one scheduled task in the background.
 *
 * A "run now" from the dashboard can mean an hour of scraping, which is not
 * something an HTTP request should hold open. The run row is created in the
 * request so the client has something to poll, and the work happens here.
 */
class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt only. The runner records the outcome on the run row, so a
     * retry would re-execute work that has already reported how it went --
     * and for a bulk scrape that is an hour of duplicated API calls.
     */
    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public readonly int $taskId,
        public readonly int $runId,
        public readonly bool $ignoreCondition = false,
    ) {}

    public function handle(ScheduledTaskRunner $runner): void
    {
        $task = ScheduledTask::find($this->taskId);
        $run = ScheduledTaskRun::find($this->runId);

        if ($task === null || $run === null || $run->isTerminal()) {
            return;
        }

        $runner->runPrepared($task, $run, $this->ignoreCondition);
    }

    /**
     * Reached when the job itself dies -- a timeout, a worker restart -- rather
     * than when the command reports a failure, so a run is never left showing
     * "running" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $run = ScheduledTaskRun::find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => ScheduledTaskRun::STATUS_FAILED,
            'error' => $exception === null
                ? 'The job failed without reporting a reason.'
                : mb_substr($exception->getMessage(), 0, 2000),
            'finished_at' => now(),
        ])->save();

        ScheduledTask::whereKey($this->taskId)->update(['last_failure_at' => now()]);
    }
}

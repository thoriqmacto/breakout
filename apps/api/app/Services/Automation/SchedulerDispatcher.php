<?php

namespace App\Services\Automation;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns Laravel's one static every-minute entry into whatever the database
 * says should run.
 *
 * routes/console.php holds a single line -- `scheduler:dispatch` every minute
 * -- so enabling, editing or deleting an automation from the dashboard takes
 * effect on the next tick with no deploy and no second scheduler mechanism to
 * keep in step.
 *
 * Occurrences are computed per task in that task's own timezone, so "0 16 * * *"
 * on an Asia/Jakarta task fires at 16:00 WIB no matter what the server clock
 * is set to. The occurrence is then stored as a UTC instant, which is what
 * makes duplicate detection exact rather than approximate.
 */
class SchedulerDispatcher
{
    public function __construct(private readonly ScheduledTaskRunner $runner) {}

    /**
     * Run every task whose occurrence falls in the window ending now.
     *
     * @return array{
     *     dispatched: array<int, array<string, mixed>>,
     *     considered: int,
     *     duplicates: int,
     *     budget_exhausted: bool
     * }
     */
    public function dispatch(?Carbon $now = null): array
    {
        $now = ($now ? $now->copy() : Carbon::now())->utc()->startOfMinute();
        $budgetSeconds = max(30, (int) config('automation.dispatch_budget_seconds', 3300));
        $deadline = microtime(true) + $budgetSeconds;

        $tasks = ScheduledTask::query()
            ->enabled()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $dispatched = [];
        $duplicates = 0;
        $considered = 0;
        $budgetExhausted = false;

        // Priority order, executed one at a time in this process. The daily
        // OHLCV job (priority 10) therefore finishes before the weekly broker
        // summary (priority 20) begins, even though both are nominally due at
        // 16:00 -- and the shared Stockbit lock enforces the same ordering
        // across processes.
        foreach ($tasks as $task) {
            foreach ($this->dueOccurrences($task, $now) as $occurrence) {
                $considered++;

                if (microtime(true) >= $deadline) {
                    $budgetExhausted = true;

                    Log::warning('automation.dispatch.budget_exhausted', [
                        'task_id' => $task->id,
                        'slug' => $task->slug,
                        'scheduled_for' => $occurrence->toIso8601String(),
                    ]);

                    break 2;
                }

                try {
                    $run = $this->runner->runScheduled($task, $occurrence);
                } catch (Throwable $exception) {
                    // One broken task must not stop the rest of the pass.
                    Log::error('automation.dispatch.failed', [
                        'task_id' => $task->id,
                        'slug' => $task->slug,
                        'scheduled_for' => $occurrence->toIso8601String(),
                        'message' => $exception->getMessage(),
                    ]);

                    continue;
                }

                if ($run === null) {
                    // Someone else already owns this occurrence. Normal.
                    $duplicates++;

                    continue;
                }

                $dispatched[] = [
                    'task_id' => $task->id,
                    'slug' => $task->slug,
                    'run_id' => $run->id,
                    'scheduled_for' => $occurrence->toIso8601String(),
                    'status' => $run->status,
                    'skip_reason' => $run->skip_reason,
                ];
            }
        }

        return [
            'dispatched' => $dispatched,
            'considered' => $considered,
            'duplicates' => $duplicates,
            'budget_exhausted' => $budgetExhausted,
        ];
    }

    /**
     * Occurrences of this task in the catch-up window ending at $now, oldest
     * first, that have not already been recorded.
     *
     * The window exists because a cron that stalls for a minute or two should
     * not silently drop the 16:00 run. It is deliberately short: replaying a
     * weekend of missed occurrences after an outage would be worse than
     * missing them. The unique index, not this window, is what guarantees each
     * occurrence runs at most once.
     *
     * @return array<int, Carbon>
     */
    public function dueOccurrences(ScheduledTask $task, Carbon $now): array
    {
        $cron = $task->cron();

        if ($cron === null) {
            Log::warning('automation.dispatch.invalid_cron', [
                'task_id' => $task->id,
                'slug' => $task->slug,
                'cron_expression' => $task->cron_expression,
            ]);

            return [];
        }

        $zone = $task->resolvedTimezone();
        $catchUp = max(0, (int) config('automation.catch_up_minutes', 5));

        $now = $now->copy()->utc()->startOfMinute();
        $occurrences = [];

        for ($offset = $catchUp; $offset >= 0; $offset--) {
            $candidateUtc = $now->copy()->subMinutes($offset);

            // isDue() is evaluated on the wall clock in the task's own zone,
            // which is the whole point: 16:00 means 16:00 in Jakarta.
            $localMinute = $candidateUtc->copy()->setTimezone($zone);

            if (! $cron->isDue($localMinute->toDateTime(), $zone->getName())) {
                continue;
            }

            $occurrences[] = $candidateUtc;
        }

        if ($occurrences === []) {
            return [];
        }

        // A cheap pre-filter. The unique index is still the authority; this
        // only avoids attempting an insert that is certain to be rejected,
        // and keeps the catch-up window from generating noise every minute.
        $alreadyRecorded = ScheduledTaskRun::query()
            ->where('scheduled_task_id', $task->id)
            ->whereIn('scheduled_for', array_map(
                static fn (Carbon $occurrence): string => $occurrence->toDateTimeString(),
                $occurrences,
            ))
            ->pluck('scheduled_for')
            ->map(static fn ($value): string => Carbon::parse($value)->utc()->toDateTimeString())
            ->all();

        return array_values(array_filter(
            $occurrences,
            static fn (Carbon $occurrence): bool => ! in_array($occurrence->toDateTimeString(), $alreadyRecorded, true),
        ));
    }
}

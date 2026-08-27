<?php

namespace App\Console\Commands\Automation;

use App\Models\ScheduledTask;
use App\Services\Automation\StockbitTokenHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * What the database scheduler currently believes.
 *
 * `php artisan schedule:list` shows only the one static entry, which is
 * correct but unhelpful when the question is "why did the 16:00 job not run".
 * This shows the rows that entry actually dispatches: their schedules in their
 * own timezone, when each is next due, and how the last attempt ended.
 */
class SchedulerStatusCommand extends Command
{
    protected $signature = 'scheduler:status {--all : Include disabled tasks}';

    protected $description = 'Show the database-managed scheduled tasks, their next run and their last outcome.';

    public function handle(StockbitTokenHealth $tokenHealth): int
    {
        $query = ScheduledTask::query()->with('latestRun')->orderBy('priority')->orderBy('id');

        if (! $this->option('all')) {
            $query->enabled();
        }

        $tasks = $query->get();

        $this->line('Scheduler timezone: '.config('automation.timezone').' (market schedules)');
        $this->line('Application timezone: '.config('app.timezone').' (storage)');
        $this->line('Now: '.Carbon::now()->setTimezone((string) config('automation.timezone'))->toDayDateTimeString());
        $this->newLine();

        $token = $tokenHealth->status();
        $this->line('Stockbit token: '.$token['status'].($token['expires_at'] ? ' (expires '.$token['expires_at'].')' : ''));
        $this->newLine();

        if ($tasks->isEmpty()) {
            $this->warn('No scheduled tasks are configured.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($tasks as $task) {
            $next = $task->nextRunAt();
            $lastRun = $task->runs()->latest('id')->first();

            $rows[] = [
                $task->slug,
                $task->command,
                $task->cron_expression.' '.$task->timezone,
                $task->condition,
                $task->enabled ? 'yes' : 'no',
                $next
                    ? $next->copy()->setTimezone($task->resolvedTimezone())->toDateTimeString()
                    : 'invalid cron',
                $lastRun ? $lastRun->status.($lastRun->skip_reason ? ' ('.$lastRun->skip_reason.')' : '') : '—',
            ];
        }

        $this->table(
            ['Slug', 'Command', 'Schedule', 'Condition', 'Enabled', 'Next run (local)', 'Last status'],
            $rows,
        );

        $this->newLine();
        $this->line('Cron must invoke "php artisan schedule:run" every minute for any of this to fire.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\Automation;

use App\Services\Automation\SchedulerDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The single static scheduler entry.
 *
 * routes/console.php runs this every minute and nothing else. It reads the
 * enabled rows in `scheduled_tasks`, works out which are due, and executes
 * them -- so enabling, editing or removing an automation from the dashboard
 * takes effect on the next tick and never requires a deploy or a second
 * scheduling mechanism to stay in step with the first.
 */
class SchedulerDispatchCommand extends Command
{
    protected $signature = 'scheduler:dispatch
        {--at= : Evaluate as though it were this moment (ISO-8601, for diagnosis)}';

    protected $description = 'Run every database-managed scheduled task that is due.';

    public function handle(SchedulerDispatcher $dispatcher): int
    {
        $at = $this->option('at');
        $now = null;

        if (is_string($at) && trim($at) !== '') {
            try {
                $now = Carbon::parse(trim($at));
            } catch (\Throwable) {
                $this->error('--at must be a parseable date/time.');

                return self::INVALID;
            }
        }

        $result = $dispatcher->dispatch($now);

        if ($result['dispatched'] === []) {
            // Quiet by default. This runs 1,440 times a day and a line per
            // tick would bury the runs that matter.
            $this->line(sprintf(
                'Nothing due. (%d occurrence(s) considered, %d already claimed elsewhere.)',
                $result['considered'],
                $result['duplicates'],
            ));

            return self::SUCCESS;
        }

        foreach ($result['dispatched'] as $entry) {
            $this->line(sprintf(
                '%s [%s] → %s%s',
                $entry['slug'],
                $entry['scheduled_for'],
                $entry['status'],
                $entry['skip_reason'] ? ' ('.$entry['skip_reason'].')' : '',
            ));
        }

        if ($result['budget_exhausted']) {
            $this->warn('The dispatch budget was exhausted; remaining due tasks will be picked up on the next tick.');
        }

        return self::SUCCESS;
    }
}

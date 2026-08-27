<?php

namespace Tests\Feature\Automation;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\TradingCalendarDay;
use App\Services\Automation\SchedulerDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The dispatcher is the only thing between Laravel's one static entry and the
 * database rows, so these cover the four ways it could go wrong: firing what
 * should not fire, not firing what should, firing the same occurrence twice,
 * and firing at the wrong hour because the timezone was ignored.
 */
class SchedulerDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'automation.timezone' => 'Asia/Jakarta',
            'automation.catch_up_minutes' => 0,
        ]);

        // The migration installs the three system automations. They are
        // covered by their own test; here they would just add runs to every
        // count, so each case starts from an empty table.
        ScheduledTask::query()->delete();

        // A harmless allowlisted command, so a dispatched run exercises the
        // whole path without touching Stockbit.
        Artisan::command('automation:test-noop', function () {
            $this->line('noop');

            return 0;
        })->purpose('Test-only no-op.');

        config(['automation.commands.automation:test-noop' => [
            'label' => 'No-op',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [],
        ]]);
    }

    private function task(array $overrides = []): ScheduledTask
    {
        return ScheduledTask::query()->create(array_merge([
            'name' => 'Test task',
            'slug' => 'test-task-'.bin2hex(random_bytes(3)),
            'command' => 'automation:test-noop',
            'parameters' => ['arguments' => [], 'options' => []],
            'cron_expression' => '0 16 * * *',
            'timezone' => 'Asia/Jakarta',
            'condition' => ScheduledTask::CONDITION_NONE,
            'priority' => 100,
            'enabled' => true,
        ], $overrides));
    }

    /** 16:00 in Jakarta is 09:00 UTC. */
    private function sixteenHundredWib(string $date = '2026-08-28'): Carbon
    {
        return Carbon::parse($date.' 09:00:00', 'UTC');
    }

    public function test_an_enabled_due_task_runs(): void
    {
        $task = $this->task();

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $run = ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame(ScheduledTaskRun::TRIGGER_SCHEDULE, $run->trigger);
        $this->assertNotNull($task->fresh()->last_success_at);
    }

    public function test_a_disabled_task_does_not_run(): void
    {
        $task = $this->task(['enabled' => false]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $this->assertSame(0, ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_a_task_that_is_not_yet_due_does_not_run(): void
    {
        $task = $this->task();

        // 15:59 WIB.
        app(SchedulerDispatcher::class)->dispatch(Carbon::parse('2026-08-28 08:59:00', 'UTC'));

        $this->assertSame(0, ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_sixteen_hundred_is_read_as_wib_and_not_as_utc(): void
    {
        $task = $this->task();
        $dispatcher = app(SchedulerDispatcher::class);

        // 16:00 UTC is 23:00 WIB; a scheduler that ignored the timezone would
        // fire here and stay quiet at 09:00 UTC.
        $dispatcher->dispatch(Carbon::parse('2026-08-28 16:00:00', 'UTC'));
        $this->assertSame(0, ScheduledTaskRun::query()->count(), '16:00 UTC is not 16:00 WIB.');

        $dispatcher->dispatch($this->sixteenHundredWib());
        $this->assertSame(1, ScheduledTaskRun::query()->count());
    }

    public function test_sixteen_hundred_wib_is_not_sixteen_hundred_in_qatar(): void
    {
        $this->task();

        // The schedule this replaced ran at 15:00 Asia/Qatar (UTC+3), i.e.
        // 12:00 UTC. Nothing should fire then.
        app(SchedulerDispatcher::class)->dispatch(Carbon::parse('2026-08-28 12:00:00', 'UTC'));

        $this->assertSame(0, ScheduledTaskRun::query()->count());
    }

    public function test_the_same_occurrence_is_never_executed_twice(): void
    {
        $task = $this->task();
        $dispatcher = app(SchedulerDispatcher::class);
        $moment = $this->sixteenHundredWib();

        $dispatcher->dispatch($moment);
        $dispatcher->dispatch($moment);
        $dispatcher->dispatch($moment);

        $this->assertSame(1, ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_a_duplicate_claim_is_rejected_by_the_database_not_only_by_the_prefilter(): void
    {
        $task = $this->task();
        $moment = $this->sixteenHundredWib();

        ScheduledTaskRun::query()->create([
            'scheduled_task_id' => $task->id,
            'scheduled_for' => $moment,
            'trigger' => ScheduledTaskRun::TRIGGER_SCHEDULE,
            'status' => ScheduledTaskRun::STATUS_RUNNING,
        ]);

        // Insert the row the pre-filter would have found, then bypass the
        // pre-filter entirely: the unique index has to be what stops this.
        $this->expectException(QueryException::class);

        ScheduledTaskRun::query()->create([
            'scheduled_task_id' => $task->id,
            'scheduled_for' => $moment,
            'trigger' => ScheduledTaskRun::TRIGGER_SCHEDULE,
            'status' => ScheduledTaskRun::STATUS_PENDING,
        ]);
    }

    public function test_an_overlapping_run_is_blocked_and_recorded(): void
    {
        $task = $this->task();

        // Hold the task's lock as though a previous run were still going.
        $held = Cache::lock('automation:task:'.$task->id, 120);
        $this->assertTrue($held->get());

        try {
            app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());
        } finally {
            $held->release();
        }

        $run = ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_SKIPPED, $run->status);
        $this->assertSame('overlapping_run', $run->skip_reason);
    }

    public function test_priority_decides_the_order_two_tasks_due_at_the_same_minute_run_in(): void
    {
        $second = $this->task(['name' => 'Second', 'priority' => 20]);
        $first = $this->task(['name' => 'First', 'priority' => 10]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $runs = ScheduledTaskRun::query()->orderBy('id')->get();

        $this->assertCount(2, $runs);
        $this->assertSame($first->id, $runs[0]->scheduled_task_id);
        $this->assertSame($second->id, $runs[1]->scheduled_task_id);
    }

    public function test_a_non_trading_day_is_skipped_without_running_the_command(): void
    {
        $task = $this->task(['condition' => ScheduledTask::CONDITION_TRADING_DAY]);

        TradingCalendarDay::create([
            'date' => '2026-08-28',
            'is_trading_day' => false,
            'is_weekend' => false,
            'is_holiday' => true,
            'holiday_reason' => 'Bourse Holiday',
        ]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_SKIPPED, $run->status);
        $this->assertSame('not_trading_day', $run->skip_reason);
        $this->assertNull($run->exit_code, 'The command must not have been invoked.');
        $this->assertSame('2026-08-28', $run->metadata['market_date']);
    }

    public function test_a_trading_day_runs_the_command(): void
    {
        $this->task(['condition' => ScheduledTask::CONDITION_TRADING_DAY]);

        TradingCalendarDay::create([
            'date' => '2026-08-28',
            'is_trading_day' => true,
            'is_weekend' => false,
            'is_holiday' => false,
        ]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $this->assertSame(ScheduledTaskRun::STATUS_SUCCESS, ScheduledTaskRun::query()->sole()->status);
    }

    public function test_a_missing_calendar_row_skips_rather_than_assuming_a_holiday(): void
    {
        $this->task(['condition' => ScheduledTask::CONDITION_TRADING_DAY]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_SKIPPED, $run->status);
        $this->assertSame('trading_calendar_incomplete', $run->skip_reason);
    }

    public function test_an_invalid_cron_expression_dispatches_nothing(): void
    {
        $this->task(['cron_expression' => 'not a cron']);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $this->assertSame(0, ScheduledTaskRun::query()->count());
    }

    public function test_a_command_removed_from_the_allowlist_fails_instead_of_executing(): void
    {
        $task = $this->task();

        config(['automation.commands' => []]);

        app(SchedulerDispatcher::class)->dispatch($this->sixteenHundredWib());

        $run = ScheduledTaskRun::query()->sole();

        $this->assertSame(ScheduledTaskRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('allowlist', (string) $run->error);
        $this->assertSame($task->id, $run->scheduled_task_id);
    }

    public function test_the_catch_up_window_picks_up_a_missed_minute_exactly_once(): void
    {
        config(['automation.catch_up_minutes' => 5]);

        $this->task(['cron_expression' => '0 16 * * *']);

        // The dispatcher was down at 16:00 and first runs at 16:03 WIB.
        $dispatcher = app(SchedulerDispatcher::class);
        $dispatcher->dispatch(Carbon::parse('2026-08-28 09:03:00', 'UTC'));

        $run = ScheduledTaskRun::query()->sole();
        $this->assertSame('2026-08-28 09:00:00', $run->scheduled_for->utc()->toDateTimeString());

        // A minute later it must not run the same occurrence again.
        $dispatcher->dispatch(Carbon::parse('2026-08-28 09:04:00', 'UTC'));
        $this->assertSame(1, ScheduledTaskRun::query()->count());
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD over the scheduler, and the boundary that matters: the browser can
 * choose from an allowlist and fill in declared parameters, and cannot
 * describe anything else.
 */
class ScheduledTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nightly calendar rebuild',
            'command' => 'trading-calendar:build',
            'parameters' => ['arguments' => [], 'options' => ['from' => '2026-01-01']],
            'cron_expression' => '0 2 * * *',
            'timezone' => 'Asia/Jakarta',
            'condition' => 'none',
            'priority' => 50,
            'enabled' => true,
        ], $overrides);
    }

    private function actAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_the_system_automations_are_installed_by_the_migrations(): void
    {
        $this->actAsUser();

        $slugs = ScheduledTask::query()->pluck('slug')->all();

        $this->assertContains('trading-calendar-refresh', $slugs);
        $this->assertContains('daily-ohlcv-sync', $slugs);
        $this->assertContains('daily-broker-summary', $slugs);
        $this->assertContains('daily-analysis-refresh', $slugs);
        $this->assertContains('stockbit-token-reminder', $slugs);

        $daily = ScheduledTask::query()->where('slug', 'daily-ohlcv-sync')->sole();
        $this->assertSame('0 18 * * *', $daily->cron_expression);
        $this->assertSame('Asia/Jakarta', $daily->timezone);
        $this->assertSame(ScheduledTask::CONDITION_TRADING_DAY, $daily->condition);
        $this->assertTrue($daily->enabled);

        $broker = ScheduledTask::query()->where('slug', 'daily-broker-summary')->sole();
        $this->assertSame('0 18 * * *', $broker->cron_expression);
        $this->assertSame(ScheduledTask::CONDITION_TRADING_DAY, $broker->condition);
        $this->assertTrue($broker->enabled);
        $this->assertGreaterThan(
            $daily->priority,
            $broker->priority,
            'The broker summary must run after the OHLCV sync, not alongside it.',
        );

        // Everything the analysis refresh reads is written by the two scrapes
        // ahead of it, so its priority has to put it last in the same pass.
        $analysis = ScheduledTask::query()->where('slug', 'daily-analysis-refresh')->sole();
        $this->assertSame('0 18 * * *', $analysis->cron_expression);
        $this->assertSame(ScheduledTask::CONDITION_NONE, $analysis->condition);
        $this->assertGreaterThan($broker->priority, $analysis->priority);

        // Retired rather than deleted: the weekly job's run history is the
        // record of every week already collected.
        $weekly = ScheduledTask::query()->where('slug', 'weekly-broker-summary')->first();
        $this->assertTrue($weekly === null || ! $weekly->enabled);

        $reminder = ScheduledTask::query()->where('slug', 'stockbit-token-reminder')->sole();
        $this->assertSame('0 9 * * *', $reminder->cron_expression);
        $this->assertSame('Asia/Jakarta', $reminder->timezone);

        // The calendar every market-day condition reads has to be refreshed
        // before the jobs that read it, so it runs earlier and at the front of
        // the priority order.
        $refresh = ScheduledTask::query()->where('slug', 'trading-calendar-refresh')->sole();
        $this->assertSame('30 17 * * *', $refresh->cron_expression);
        $this->assertSame('Asia/Jakarta', $refresh->timezone);
        $this->assertSame(ScheduledTask::CONDITION_NONE, $refresh->condition);
        $this->assertTrue($refresh->enabled);
        $this->assertLessThan(
            $daily->priority,
            $refresh->priority,
            'The calendar refresh must run before the jobs whose conditions read it.',
        );
    }

    public function test_the_daily_job_is_scheduled_after_the_calendar_refresh_on_the_same_day(): void
    {
        $this->actAsUser();

        $refresh = ScheduledTask::query()->where('slug', 'trading-calendar-refresh')->sole();
        $daily = ScheduledTask::query()->where('slug', 'daily-ohlcv-sync')->sole();

        // Both are daily, so comparing the next occurrence is enough to catch
        // an edit that put the refresh after the job it feeds.
        $this->assertTrue(
            $refresh->nextRunAt(Carbon::parse('2026-08-28 00:00:00', 'UTC'))
                ->lessThan($daily->nextRunAt(Carbon::parse('2026-08-28 00:00:00', 'UTC'))),
            'The refresh must come first on any given day.',
        );
    }

    public function test_index_lists_tasks_with_the_command_catalogue(): void
    {
        $this->actAsUser();

        $response = $this->getJson('/api/v1/scheduled-tasks')->assertOk();

        $this->assertSame('Asia/Jakarta', $response->json('meta.timezone'));
        $this->assertSame('UTC', $response->json('meta.application_timezone'));
        $this->assertNotEmpty($response->json('meta.commands'));
        $this->assertContains('automation:ohlcv-daily', array_column($response->json('meta.commands'), 'command'));
    }

    public function test_a_task_can_be_created(): void
    {
        $this->actAsUser();

        $response = $this->postJson('/api/v1/scheduled-tasks', $this->payload())->assertStatus(201);

        $this->assertSame('nightly-calendar-rebuild', $response->json('data.slug'));
        $this->assertSame('trading-calendar:build', $response->json('data.command'));
        $this->assertSame(
            'php artisan trading-calendar:build --from=2026-01-01',
            $response->json('data.command_preview'),
        );
        $this->assertFalse($response->json('data.is_system'));
    }

    public function test_an_unapproved_artisan_command_is_rejected(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload(['command' => 'migrate:fresh']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, ScheduledTask::query()->where('command', 'migrate:fresh')->count());
    }

    public function test_a_shell_string_dressed_up_as_a_command_is_rejected(): void
    {
        $this->actAsUser();

        foreach ([
            'trading-calendar:build; rm -rf /',
            'trading-calendar:build && curl evil.example',
            '$(whoami)',
            'php artisan trading-calendar:build',
        ] as $attempt) {
            $this->postJson('/api/v1/scheduled-tasks', $this->payload(['command' => $attempt]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('command');
        }
    }

    public function test_an_undeclared_option_is_rejected(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload([
            'parameters' => ['arguments' => [], 'options' => ['holiday-file' => '/etc/passwd']],
        ]))->assertStatus(422)->assertJsonValidationErrors('parameters');
    }

    public function test_a_malformed_option_value_is_rejected(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload([
            'parameters' => ['arguments' => [], 'options' => ['from' => '2026-13-45']],
        ]))->assertStatus(422)->assertJsonValidationErrors('parameters');

        $this->postJson('/api/v1/scheduled-tasks', $this->payload([
            'command' => 'stockbit:scrape',
            'parameters' => ['arguments' => ['tickers' => ['BBCA; rm -rf /']], 'options' => []],
        ]))->assertStatus(422)->assertJsonValidationErrors('parameters');
    }

    public function test_the_stockbit_bearer_can_never_be_stored_as_a_parameter(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload([
            'command' => 'stockbit:scrape',
            'parameters' => ['arguments' => [], 'options' => ['token' => 'eyJhbGciOiJIUzI1NiJ9.e30.x']],
        ]))->assertStatus(422)->assertJsonValidationErrors('parameters');

        $this->assertStringNotContainsString(
            'eyJhbGciOiJIUzI1NiJ9',
            (string) json_encode(ScheduledTask::query()->pluck('parameters')->all()),
        );
    }

    public function test_an_invalid_cron_expression_is_rejected(): void
    {
        $this->actAsUser();

        foreach (['not a cron', '* * *', '99 99 * * *', ''] as $expression) {
            $this->postJson('/api/v1/scheduled-tasks', $this->payload(['cron_expression' => $expression]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('cron_expression');
        }
    }

    public function test_an_unknown_timezone_is_rejected(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload(['timezone' => 'Mars/Olympus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    public function test_an_unknown_condition_is_rejected(): void
    {
        $this->actAsUser();

        $this->postJson('/api/v1/scheduled-tasks', $this->payload(['condition' => 'when_i_feel_like_it']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('condition');
    }

    public function test_a_task_can_be_updated(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'daily-ohlcv-sync')->sole();

        $this->putJson('/api/v1/scheduled-tasks/'.$task->id, [
            'name' => 'Daily OHLCV Sync (17:00)',
            'cron_expression' => '0 17 * * *',
        ])->assertOk();

        $task->refresh();
        $this->assertSame('0 17 * * *', $task->cron_expression);
        $this->assertSame('Daily OHLCV Sync (17:00)', $task->name);
        // Editing a system task must not silently rewrite the parameters it
        // was seeded with.
        $this->assertSame(['arguments' => [], 'options' => []], $task->parameters);
    }

    public function test_a_seeded_task_cannot_be_edited_into_an_invalid_schedule(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'daily-broker-summary')->sole();

        $this->putJson('/api/v1/scheduled-tasks/'.$task->id, ['cron_expression' => 'every friday please'])
            ->assertStatus(422);

        $this->assertSame('0 18 * * *', $task->fresh()->cron_expression);
    }

    public function test_a_task_can_be_toggled(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'daily-ohlcv-sync')->sole();

        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/toggle')->assertOk();
        $this->assertFalse($task->fresh()->enabled);

        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/toggle', ['enabled' => true])->assertOk();
        $this->assertTrue($task->fresh()->enabled);
    }

    public function test_a_task_can_be_deleted_along_with_its_runs(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'stockbit-token-reminder')->sole();

        ScheduledTaskRun::query()->create([
            'scheduled_task_id' => $task->id,
            'trigger' => ScheduledTaskRun::TRIGGER_MANUAL,
            'status' => ScheduledTaskRun::STATUS_SUCCESS,
        ]);

        $this->deleteJson('/api/v1/scheduled-tasks/'.$task->id)->assertOk();

        $this->assertNull(ScheduledTask::query()->find($task->id));
        $this->assertSame(0, ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_run_now_queues_the_work_instead_of_holding_the_request(): void
    {
        Queue::fake();
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'stockbit-token-reminder')->sole();

        $response = $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/run')->assertStatus(202);

        $this->assertSame(ScheduledTaskRun::STATUS_PENDING, $response->json('data.status'));
        $this->assertSame(ScheduledTaskRun::TRIGGER_MANUAL, $response->json('data.trigger'));
        $this->assertNull($response->json('data.scheduled_for'));

        Queue::assertPushed(RunScheduledTaskJob::class);
    }

    public function test_run_now_on_a_disabled_task_needs_force(): void
    {
        Queue::fake();
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'stockbit-token-reminder')->sole();
        $task->forceFill(['enabled' => false])->save();

        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/run')->assertStatus(422);
        Queue::assertNothingPushed();

        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/run', ['force' => true])->assertStatus(202);
        Queue::assertPushed(RunScheduledTaskJob::class);
    }

    public function test_manual_runs_may_repeat_where_scheduled_occurrences_may_not(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'stockbit-token-reminder')->sole();

        // QUEUE_CONNECTION is sync in the test environment, so these execute.
        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/run')->assertStatus(202);
        $this->postJson('/api/v1/scheduled-tasks/'.$task->id.'/run')->assertStatus(202);

        $this->assertSame(2, ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_run_history_is_paginated(): void
    {
        $this->actAsUser();

        $task = ScheduledTask::query()->where('slug', 'daily-ohlcv-sync')->sole();

        foreach (range(1, 30) as $index) {
            ScheduledTaskRun::query()->create([
                'scheduled_task_id' => $task->id,
                'trigger' => ScheduledTaskRun::TRIGGER_MANUAL,
                'status' => ScheduledTaskRun::STATUS_SUCCESS,
                'metadata' => ['market_date' => '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)],
            ]);
        }

        $response = $this->getJson('/api/v1/scheduled-tasks/'.$task->id.'/runs?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(30, $response->json('meta.total'));

        $this->getJson('/api/v1/scheduled-tasks/'.$task->id.'/runs?per_page=500')->assertStatus(422);
    }

    public function test_the_scheduler_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/scheduled-tasks')->assertStatus(401);
        $this->postJson('/api/v1/scheduled-tasks', $this->payload())->assertStatus(401);
        $this->getJson('/api/v1/automation/status')->assertStatus(401);
    }

    public function test_the_status_endpoint_reports_the_market_timezone_and_the_next_run(): void
    {
        $this->actAsUser();

        $response = $this->getJson('/api/v1/automation/status')->assertOk();

        $this->assertSame('Asia/Jakarta', $response->json('data.scheduler.timezone'));
        $this->assertSame('Asia/Jakarta (WIB / UTC+7)', $response->json('data.scheduler.timezone_label'));
        $this->assertSame('UTC', $response->json('data.scheduler.application_timezone'));
        $this->assertNotNull($response->json('data.scheduler.next_run.slug'));
        $this->assertNotNull($response->json('data.stockbit_token.status'));
        $this->assertArrayHasKey('trading_calendar', $response->json('data'));
    }
}

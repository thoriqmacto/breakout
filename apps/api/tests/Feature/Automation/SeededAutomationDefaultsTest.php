<?php

namespace Tests\Feature\Automation;

use App\Models\ScheduledTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every default automation must reach a database that already exists.
 *
 * The August seeding migration inserts everything in
 * `config('automation.defaults')`, but it reads that config when it runs, and
 * a migration runs once. A database migrated in August holds the automations
 * that existed in August. Every entry added since reached fresh installs only.
 *
 * Which is why asserting against a freshly migrated database proves nothing
 * here: it passes for an automation no deployed server has, because the test
 * database just ran the August migration against today's config. The bug lives
 * entirely in the difference between those two databases, so the test has to
 * build the older one.
 */
class SeededAutomationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The regression, reproduced: a database that predates a config entry.
     *
     * Dropping the rows is what a server migrated before those entries existed
     * actually looks like -- the automations are not disabled or misconfigured,
     * they are absent, invisible to the dispatcher and to the dashboard alike.
     * The repair migration is then run on its own and has to restore them.
     */
    public function test_a_database_migrated_before_a_default_existed_still_receives_it(): void
    {
        $defaults = (array) config('automation.defaults', []);
        $this->assertNotEmpty($defaults, 'There are no default automations to check.');

        $slugs = array_values(array_filter(array_map(
            static fn (array $definition): ?string => $definition['slug'] ?? null,
            $defaults,
        )));

        // The token renewal is the one this was found through: seeded into new
        // installs from 7 September, missing from every database migrated
        // before it, and the reason a token expired with an hourly renewal
        // job that had no row to run from.
        $this->assertContains('stockbit-token-renewal', $slugs);

        DB::table('scheduled_tasks')->whereIn('slug', $slugs)->delete();
        $this->assertSame(0, ScheduledTask::whereIn('slug', $slugs)->count());

        $this->runRepairMigration();

        foreach ($defaults as $definition) {
            $slug = (string) $definition['slug'];
            $task = ScheduledTask::where('slug', $slug)->first();

            $this->assertNotNull($task, sprintf(
                'The "%s" automation is in config(\'automation.defaults\') but nothing seeds it '
                .'into an existing database, so the task never runs there. Add it to a migration.',
                $slug,
            ));

            $this->assertSame($definition['command'], $task->command);
            $this->assertSame($definition['cron_expression'], $task->cron_expression);
            $this->assertTrue($task->is_system);
        }
    }

    /**
     * A row the operator already has is theirs, edits included.
     */
    public function test_it_leaves_an_existing_row_untouched(): void
    {
        $task = ScheduledTask::where('slug', 'stockbit-token-reminder')->firstOrFail();

        $task->update(['cron_expression' => '30 7 * * *', 'enabled' => false]);

        $this->runRepairMigration();

        $task->refresh();

        $this->assertSame('30 7 * * *', $task->cron_expression);
        $this->assertFalse($task->enabled);
        $this->assertSame(1, ScheduledTask::where('slug', 'stockbit-token-reminder')->count());
    }

    private function runRepairMigration(): void
    {
        (require database_path('migrations/2026_09_09_000000_seed_missing_default_automations.php'))->up();
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Install any default automation that no existing database ever received.
 *
 * `2026_08_29_000200_seed_default_scheduled_tasks` inserts everything in
 * `config('automation.defaults')` -- but it reads that config when it runs,
 * and a migration runs once. A database migrated in August holds exactly the
 * automations that existed in August; every entry added to the config since
 * then reached fresh installs only, because their first migration read the
 * newer file.
 *
 * That gap is silent in both directions. Nothing reports a default with no
 * row, and the dashboard cannot show a task it has no record of, so the
 * automation is not disabled or failing -- it is absent, and looks like a
 * feature that was never built. The Stockbit token renewal spent a fortnight
 * that way: the daily reminder had a row and dutifully warned that the token
 * was expiring, while the hourly job that would have renewed it did not exist
 * to run. Each earlier addition got a hand-written migration of its own; this
 * one was missed, and the outage that followed is what found it.
 *
 * So this repeats the original loop rather than naming a slug. It is a no-op
 * on a database that already has everything -- including a fresh one, where
 * the August migration has just inserted the current list -- and it repairs
 * whatever a given database is short of, which is the only thing that differs
 * between two installs at the same commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('scheduled_tasks')->pluck('slug')->all();
        $now = Carbon::now();

        foreach ((array) config('automation.defaults', []) as $definition) {
            $slug = $definition['slug'] ?? null;

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            // An operator may have created the task by hand, or edited the
            // seeded one. Either way the row is theirs and is left alone.
            if (in_array($slug, $existing, true)) {
                continue;
            }

            DB::table('scheduled_tasks')->insert([
                'name' => $definition['name'],
                'slug' => $slug,
                'description' => $definition['description'] ?? null,
                'command' => $definition['command'],
                'parameters' => json_encode($definition['parameters'] ?? ['arguments' => [], 'options' => []]),
                'cron_expression' => $definition['cron_expression'],
                'timezone' => $definition['timezone'] ?? config('automation.timezone', 'Asia/Jakarta'),
                'condition' => $definition['condition'] ?? 'none',
                'priority' => $definition['priority'] ?? 100,
                // Faithful to the definition, which ships the token renewal
                // disabled: it launches a browser, and on an install with no
                // browser auth configured every hourly run would stand down
                // and raise the reminder. Enabling it is one switch in the
                // dashboard, and belongs to whoever set the credentials up.
                'enabled' => $definition['enabled'] ?? true,
                'sync_gdrive_after_success' => $definition['sync_gdrive_after_success'] ?? false,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back cannot tell a row this migration inserted from one the
     * August seeding put there, and deleting a live automation to undo a
     * repair would be a worse outcome than leaving it in place.
     */
    public function down(): void {}
};

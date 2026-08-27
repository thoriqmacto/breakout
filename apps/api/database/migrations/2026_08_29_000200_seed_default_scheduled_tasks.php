<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Install the three system automations so a fresh deployment schedules the
     * daily OHLCV sync, the weekly broker summary and the token reminder
     * without anyone remembering to run a seeder.
     *
     * Definitions come from config('automation.defaults') so the migration and
     * AutomationSeeder cannot drift apart. Existing rows are left untouched:
     * these are editable from the dashboard and a re-run must not undo that.
     */
    public function up(): void
    {
        $now = Carbon::now();

        foreach ((array) config('automation.defaults', []) as $definition) {
            $slug = $definition['slug'] ?? null;

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            if (DB::table('scheduled_tasks')->where('slug', $slug)->exists()) {
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
                'enabled' => $definition['enabled'] ?? true,
                'sync_gdrive_after_success' => $definition['sync_gdrive_after_success'] ?? false,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Remove only the seeded rows, matched by slug. Anything the operator
     * created afterwards is theirs and is left alone.
     */
    public function down(): void
    {
        $slugs = array_values(array_filter(array_map(
            static fn ($definition) => $definition['slug'] ?? null,
            (array) config('automation.defaults', []),
        )));

        if ($slugs === []) {
            return;
        }

        DB::table('scheduled_tasks')->whereIn('slug', $slugs)->where('is_system', true)->delete();
    }
};

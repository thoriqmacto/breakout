<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Move broker summaries from weekly to daily, and add the analysis refresh
 * that turns the day's imports into the numbers the dashboard reads.
 *
 * The weekly job produced one aggregate per week, which meant the broker
 * summary page and everything derived from it only advanced on Fridays. The
 * daily job collects the same thing every trading day and backfills whatever
 * each asset is behind by, so the page is current on the day rather than at
 * the end of the week.
 *
 * The weekly row is disabled, not deleted: its run history is the record of
 * every week already collected, and the command behind it still works for
 * anyone who wants a week on purpose. Only a row that is still enabled and
 * still marked is_system is touched -- one somebody has already disabled, or
 * re-created as their own, is left exactly as it is.
 */
return new class extends Migration
{
    private const NEW_SLUGS = ['daily-broker-summary', 'daily-analysis-refresh'];

    private const RETIRED_SLUG = 'weekly-broker-summary';

    public function up(): void
    {
        $now = Carbon::now();

        foreach (self::NEW_SLUGS as $slug) {
            $definition = $this->definition($slug);

            if ($definition === null || DB::table('scheduled_tasks')->where('slug', $slug)->exists()) {
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

        DB::table('scheduled_tasks')
            ->where('slug', self::RETIRED_SLUG)
            ->where('is_system', true)
            ->where('enabled', true)
            ->update(['enabled' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $now = Carbon::now();

        DB::table('scheduled_tasks')
            ->whereIn('slug', self::NEW_SLUGS)
            ->where('is_system', true)
            ->delete();

        DB::table('scheduled_tasks')
            ->where('slug', self::RETIRED_SLUG)
            ->where('is_system', true)
            ->where('enabled', false)
            ->update(['enabled' => true, 'updated_at' => $now]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definition(string $slug): ?array
    {
        foreach ((array) config('automation.defaults', []) as $candidate) {
            if (($candidate['slug'] ?? null) === $slug) {
                return $candidate;
            }
        }

        return null;
    }
};

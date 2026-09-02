<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed the nightly reconciliation task.
 *
 * Priority 25 places it between broker-summary collection (20) and the
 * analysis refresh (30), which is the ordering the recovery layer depends on:
 * it must read what the collectors wrote, and the analysis must not be waiting
 * on it. Running earlier would reconcile a half-collected evening and publish
 * that as the recovery copy.
 *
 * Skipped if a row with this slug already exists, so an operator who created
 * it by hand keeps their schedule.
 */
return new class extends Migration
{
    private const SLUG = 'daily-data-reconciliation';

    public function up(): void
    {
        if (DB::table('scheduled_tasks')->where('slug', self::SLUG)->exists()) {
            return;
        }

        $definition = $this->definition();

        if ($definition === null) {
            return;
        }

        $now = Carbon::now();

        DB::table('scheduled_tasks')->insert([
            'name' => $definition['name'],
            'slug' => self::SLUG,
            'description' => $definition['description'],
            'command' => $definition['command'],
            'parameters' => json_encode($definition['parameters'] ?? ['arguments' => [], 'options' => []]),
            'cron_expression' => $definition['cron_expression'],
            'timezone' => $definition['timezone'] ?? config('automation.timezone', 'Asia/Jakarta'),
            'condition' => $definition['condition'] ?? 'none',
            'priority' => $definition['priority'] ?? 25,
            'enabled' => $definition['enabled'] ?? true,
            'sync_gdrive_after_success' => $definition['sync_gdrive_after_success'] ?? false,
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')
            ->where('slug', self::SLUG)
            ->where('is_system', true)
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definition(): ?array
    {
        foreach ((array) config('automation.defaults', []) as $candidate) {
            if (($candidate['slug'] ?? null) === self::SLUG) {
                return $candidate;
            }
        }

        return null;
    }
};

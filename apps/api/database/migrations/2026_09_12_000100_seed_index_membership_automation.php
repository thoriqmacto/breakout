<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Install the index membership sync task on databases that already exist.
 *
 * The August seeding migration reads config('automation.defaults') when it
 * runs, and a migration runs once, so an entry added to that config afterwards
 * reaches fresh installs only. The token renewal was lost exactly that way and
 * spent a fortnight invisible -- present in config, absent from every
 * dashboard, with nothing anywhere reporting the gap.
 *
 * 2026_09_09_000000_seed_missing_default_automations repeats the whole loop
 * and would pick this up too, but it has already run on the deployed database
 * and will not run again. So a new default still needs its own migration; that
 * one is the backstop for entries added between deploys, not a substitute.
 */
return new class extends Migration
{
    private const SLUG = 'index-membership-sync';

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
            'priority' => $definition['priority'] ?? 1,
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

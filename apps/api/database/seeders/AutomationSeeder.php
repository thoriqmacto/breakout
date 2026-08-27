<?php

namespace Database\Seeders;

use App\Models\ScheduledTask;
use Illuminate\Database\Seeder;

/**
 * Re-create any missing system automation.
 *
 * The migration installs these on a fresh deployment; this is the way back if
 * one was deleted, and the way to pick up a new default without a migration.
 * Existing rows are left exactly as they are -- they are editable from the
 * dashboard, and a seeder that reset someone's schedule would be a trap.
 */
class AutomationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('automation.defaults', []) as $definition) {
            $slug = $definition['slug'] ?? null;

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            if (ScheduledTask::query()->where('slug', $slug)->exists()) {
                continue;
            }

            ScheduledTask::query()->create([
                'name' => $definition['name'],
                'slug' => $slug,
                'description' => $definition['description'] ?? null,
                'command' => $definition['command'],
                'parameters' => $definition['parameters'] ?? ['arguments' => [], 'options' => []],
                'cron_expression' => $definition['cron_expression'],
                'timezone' => $definition['timezone'] ?? config('automation.timezone', 'Asia/Jakarta'),
                'condition' => $definition['condition'] ?? ScheduledTask::CONDITION_NONE,
                'priority' => $definition['priority'] ?? 100,
                'enabled' => $definition['enabled'] ?? true,
                'sync_gdrive_after_success' => $definition['sync_gdrive_after_success'] ?? false,
                'is_system' => true,
            ]);
        }
    }
}

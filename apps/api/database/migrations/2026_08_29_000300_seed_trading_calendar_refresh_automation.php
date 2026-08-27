<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Install the trading-calendar refresh automation, and move the two market
     * data jobs off the closing bell.
     *
     * Every trading-day condition reads `trading_calendar`, and nothing kept it
     * current -- so on an installation whose calendar had stopped advancing,
     * the daily and weekly jobs skipped as `trading_calendar_incomplete` every
     * single day. The new automation refreshes it at 17:30 WIB.
     *
     * The calendar is derived from Yahoo's published bars, so it cannot confirm
     * that today traded until that bar exists. 16:00 WIB is the closing bell
     * itself, which is too early for that to be true, so the jobs that depend
     * on it move to 18:00.
     */
    private const PREVIOUS_MARKET_CRON = '0 16 * * *';

    private const MARKET_CRON = '0 18 * * *';

    /**
     * The seeded slugs whose schedule this migration moves.
     */
    private const MARKET_SLUGS = ['daily-ohlcv-sync', 'weekly-broker-summary'];

    private const REFRESH_SLUG = 'trading-calendar-refresh';

    public function up(): void
    {
        $now = Carbon::now();

        $definition = $this->definition(self::REFRESH_SLUG);

        if ($definition !== null && ! DB::table('scheduled_tasks')->where('slug', self::REFRESH_SLUG)->exists()) {
            DB::table('scheduled_tasks')->insert([
                'name' => $definition['name'],
                'slug' => self::REFRESH_SLUG,
                'description' => $definition['description'] ?? null,
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

        // Only rows still sitting at the value the previous migration seeded.
        // These schedules are editable from the dashboard, and silently
        // rewriting a time somebody deliberately chose would be a trap.
        DB::table('scheduled_tasks')
            ->whereIn('slug', self::MARKET_SLUGS)
            ->where('is_system', true)
            ->where('cron_expression', self::PREVIOUS_MARKET_CRON)
            ->update(['cron_expression' => self::MARKET_CRON, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $now = Carbon::now();

        DB::table('scheduled_tasks')
            ->where('slug', self::REFRESH_SLUG)
            ->where('is_system', true)
            ->delete();

        DB::table('scheduled_tasks')
            ->whereIn('slug', self::MARKET_SLUGS)
            ->where('is_system', true)
            ->where('cron_expression', self::MARKET_CRON)
            ->update(['cron_expression' => self::PREVIOUS_MARKET_CRON, 'updated_at' => $now]);
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

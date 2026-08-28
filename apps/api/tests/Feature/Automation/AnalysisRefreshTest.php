<?php

namespace Tests\Feature\Automation;

use App\Models\Asset;
use App\Models\Price;
use App\Services\Automation\RunMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The refresh that turns imported data into the numbers the dashboard reads:
 * which days it rebuilds, and what it does when one step falls over.
 */
class AnalysisRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['automation.timezone' => 'Asia/Jakarta']);
    }

    private function assetWithBars(string $symbol, string $from, string $to): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol]);

        $close = 1000;

        for ($cursor = Carbon::parse($from); $cursor->lessThanOrEqualTo(Carbon::parse($to)); $cursor->addDay()) {
            if ($cursor->dayOfWeekIso >= 6) {
                continue;
            }

            $close += 5;

            Price::create([
                'asset_id' => $asset->id,
                'date' => $cursor->toDateString(),
                'open' => $close - 5,
                'high' => $close + 10,
                'low' => $close - 10,
                'close' => $close,
                'volume' => 1_000_000,
            ]);
        }

        return $asset;
    }

    private function seedFeatureRow(string $symbol, string $date): void
    {
        DB::table('features_daily')->insert([
            'symbol' => $symbol,
            'date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refresh(array $parameters = []): array
    {
        Artisan::call('automation:analysis-refresh', $parameters);

        return app(RunMetadata::class)->all();
    }

    public function test_it_rebuilds_from_the_newest_computed_day_through_the_newest_bar(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');
        // Features stop three sessions short of the data.
        $this->seedFeatureRow('BBCA', '2026-08-25');

        $metadata = $this->refresh();

        // The last computed day is rebuilt too, not skipped: the broker window
        // for that day is imported after the bars, and often after the features
        // for it were first written.
        $this->assertSame('2026-08-25', $metadata['features_from']);
        $this->assertSame('2026-08-28', $metadata['scan_date']);

        $dates = DB::table('features_daily')->where('symbol', 'BBCA')->orderBy('date')->pluck('date')
            ->map(static fn ($value): string => Carbon::parse($value)->toDateString())
            ->all();

        $this->assertContains('2026-08-26', $dates);
        $this->assertContains('2026-08-27', $dates);
        $this->assertContains('2026-08-28', $dates);
    }

    public function test_the_scan_date_follows_the_data_and_not_the_clock(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-26');

        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        try {
            $metadata = $this->refresh();
        } finally {
            Carbon::setTestNow();
        }

        // The scrape has not landed for the 27th or 28th, so claiming those
        // dates would stamp derived rows with days no bar exists for.
        $this->assertSame('2026-08-26', $metadata['scan_date']);
    }

    public function test_a_long_gap_is_rebuilt_a_bounded_slice_at_a_time(): void
    {
        $this->assetWithBars('BBCA', '2026-06-01', '2026-08-28');
        $this->seedFeatureRow('BBCA', '2026-06-05');

        $metadata = $this->refresh(['--max-days' => 3]);

        // Three days ending on the newest bar, rather than three months in one
        // run that would hold the whole dispatcher budget.
        $this->assertSame('2026-08-26', $metadata['features_from']);
        $this->assertSame('2026-08-28', $metadata['scan_date']);
        $this->assertSame(3, $metadata['days_rebuilt']);
    }

    public function test_every_step_runs_and_is_reported(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');

        $metadata = $this->refresh();

        $this->assertSame(
            ['features', 'metrics', 'rollup', 'watchlist', 'strategies'],
            array_keys($metadata['steps']),
            'The order is the dependency graph: features and rollups before the things that read them.',
        );

        foreach ($metadata['steps'] as $name => $step) {
            $this->assertSame('ok', $step['status'], sprintf('The %s step did not complete.', $name));
        }

        $this->assertSame([], $metadata['failed_steps']);
        $this->assertFalse($metadata['partial']);

        // asset:metrics prompts when given neither --all nor --sym, and a
        // scheduled run has nobody to answer it.
        $this->assertSame(1, DB::table('metrics')->where('symbol', 'BBCA')->count());
    }

    public function test_a_step_can_be_skipped_without_stopping_the_rest(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');

        $metadata = $this->refresh(['--skip-features' => true, '--skip-strategies' => true]);

        $this->assertSame('skipped', $metadata['steps']['features']['status']);
        $this->assertSame('skipped', $metadata['steps']['strategies']['status']);
        $this->assertSame('ok', $metadata['steps']['metrics']['status']);
        $this->assertSame('ok', $metadata['steps']['watchlist']['status']);
        $this->assertSame(0, DB::table('features_daily')->count());
    }

    public function test_a_failing_step_is_recorded_and_the_rest_still_run(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');

        // A broken downstream table. Abandoning the remaining steps because one
        // struggled would leave more of the dashboard stale, not less.
        Schema::drop('metrics');

        $exitCode = Artisan::call('automation:analysis-refresh');
        $metadata = app(RunMetadata::class)->all();

        $this->assertSame(1, $exitCode);
        $this->assertTrue($metadata['partial']);
        $this->assertContains('metrics', $metadata['failed_steps']);
        $this->assertStringContainsString('metrics', (string) $metadata['error_summary']);

        // The step before it completed, and the chain did not abort: the ones
        // after it still ran and reported for themselves. The watchlist is
        // expected among the casualties -- WatchlistRanker reads the metrics
        // table directly, which is exactly why metrics is ordered ahead of it.
        $this->assertSame('ok', $metadata['steps']['features']['status']);
        $this->assertSame('ok', $metadata['steps']['rollup']['status']);
        $this->assertSame('ok', $metadata['steps']['strategies']['status']);
    }

    public function test_it_reports_honestly_when_there_is_nothing_to_derive(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);

        $metadata = $this->refresh();

        $this->assertTrue($metadata['skipped']);
        $this->assertSame('no_price_bars', $metadata['skip_reason']);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');

        $this->refresh();

        $features = DB::table('features_daily')->count();
        $metrics = DB::table('metrics')->count();

        $this->refresh();

        $this->assertSame($features, DB::table('features_daily')->count());
        $this->assertSame($metrics, DB::table('metrics')->count());
    }

    public function test_a_malformed_date_is_rejected_rather_than_guessed(): void
    {
        $this->assetWithBars('BBCA', '2026-08-03', '2026-08-28');

        $this->assertSame(2, Artisan::call('automation:analysis-refresh', ['--date' => 'last friday']));
        $this->assertSame(0, DB::table('features_daily')->count());
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingCalendarDay;
use App\Services\AssetProfileUpdater;
use App\Services\Automation\RunMetadata;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The daily job, exercised against the real `stockbit:scrape` with the HTTP
 * client mocked -- so the assertions are about what actually reaches the API
 * and what actually lands on disk and in the database, not about a stub of the
 * scraper agreeing with a stub of itself.
 */
class OhlcvDailyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-ohlcv-daily-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0755, true);

        config([
            'automation.timezone' => 'Asia/Jakarta',
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_disk' => null,
            'csv.mirror_path' => 'seeds/historical',
            'stockbit.save_disk' => 'local',
            'stockbit.historical.period' => 'HS_PERIOD_DAILY',
            'stockbit.historical.page' => 1,
        ]);

        // The mirror manifest lives on the real local disk, not the faked
        // one, so it survives between tests and would make a second run report
        // "already mirrored" for a file this test expects to be uploaded.
        @unlink(storage_path('app/bar-csv-mirror.json'));

        app(StockbitTokenResolver::class)->persist($this->jwt());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->seedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->seedDir);
        @unlink(storage_path('app/bar-csv-mirror.json'));

        Mockery::close();
        parent::tearDown();
    }

    private function jwt(): string
    {
        $encode = static fn (array $claims): string => rtrim(strtr(base64_encode(
            (string) json_encode($claims)
        ), '+/', '-_'), '=');

        return $encode(['alg' => 'HS256']).'.'.$encode(['exp' => Carbon::now()->addDays(3)->getTimestamp()]).'.sig';
    }

    private function tradingDay(string $date, bool $isTradingDay = true): void
    {
        TradingCalendarDay::create([
            'date' => $date,
            'is_trading_day' => $isTradingDay,
            'is_weekend' => false,
            'is_holiday' => ! $isTradingDay,
        ]);
    }

    private function asset(string $symbol, bool $syncPrice = true): Asset
    {
        return Asset::create(['symbol' => $symbol, 'name' => $symbol, 'sync_price' => $syncPrice]);
    }

    private function stubProfileUpdater(): void
    {
        $mock = Mockery::mock(AssetProfileUpdater::class);
        $mock->shouldReceive('withoutSeederSync')->withAnyArgs()->andReturnSelf()->byDefault();
        $mock->shouldReceive('takeSeederProfileGaps')->withAnyArgs()->andReturn([])->byDefault();
        $mock->shouldReceive('getIPODate')->withAnyArgs()->andReturnNull()->byDefault();
        $mock->shouldReceive('applyTickerProfileResponse')->withAnyArgs()->andReturn([
            'ok' => true, 'asset' => (object) ['profile_synced_at' => null], 'profile' => [],
        ])->byDefault();

        $this->app->instance(AssetProfileUpdater::class, $mock);
    }

    /**
     * @return MockInterface&StockbitExodusClient
     */
    private function stockbit()
    {
        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('setBearer')->withAnyArgs()->andReturnNull()->byDefault();
        $this->app->instance(StockbitExodusClient::class, $mock);

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function bar(string $date): array
    {
        return [
            'result' => [[
                'date' => $date,
                'open' => 1000, 'high' => 1100, 'low' => 990, 'close' => 1050, 'volume' => 12345,
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<string, mixed>
     */
    private function bars(array $dates): array
    {
        return [
            'result' => array_map(static fn (string $date): array => [
                'date' => $date,
                'open' => 1000, 'high' => 1100, 'low' => 990, 'close' => 1050, 'volume' => 12345,
            ], $dates),
        ];
    }

    private function storedBar(Asset $asset, string $date): void
    {
        Price::create([
            'asset_id' => $asset->id,
            'date' => $date,
            'open' => 900, 'high' => 950, 'low' => 890, 'close' => 920, 'volume' => 1000,
        ]);
    }

    /**
     * Mon 24 .. Fri 28 August 2026, all traded.
     */
    private function tradingWeek(): void
    {
        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28'] as $date) {
            $this->tradingDay($date);
        }
    }

    private function hasBar(Asset $asset, string $date): bool
    {
        return Price::query()->where('asset_id', $asset->id)->whereDate('date', $date)->exists();
    }

    /**
     * The case this recovery exists for.
     *
     * The token died after Monday's run. The calendar kept advancing on its
     * own -- it is built from Yahoo and needs no Stockbit token -- so it knows
     * Tuesday and Wednesday traded. Thursday's run used to ask for Thursday
     * alone and leave those two missing for good.
     */
    public function test_sessions_missed_while_the_token_was_dead_are_recovered_on_the_next_run(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingWeek();
        $this->storedBar($asset, '2026-08-24');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        // One request, widened back to the first missed session.
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-25', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bars(['2026-08-25', '2026-08-26', '2026-08-27'])]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-27']);

        $this->assertTrue($this->hasBar($asset, '2026-08-25'));
        $this->assertTrue($this->hasBar($asset, '2026-08-26'));
        $this->assertTrue($this->hasBar($asset, '2026-08-27'));

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame(1, $metadata['backfilled_ticker_count']);
        $this->assertSame(['2026-08-25', '2026-08-26'], $metadata['backfill_sessions']);
        $this->assertSame(0, $metadata['backfill_unrecovered_count']);
        $this->assertFalse($metadata['partial']);
    }

    /**
     * Why this checks a window rather than following the latest bar.
     *
     * After an outage somebody fills today by hand with --date. The newest
     * bar is now current, so a "resume after the latest bar" cursor would
     * decide nothing is missing and the days before it would never come back.
     */
    public function test_a_hole_behind_the_newest_bar_is_still_found(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingWeek();
        $this->storedBar($asset, '2026-08-24');
        $this->storedBar($asset, '2026-08-27');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-25', '2026-08-28', null, 1)
            ->andReturn(['data' => $this->bars(['2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28'])]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $this->assertTrue($this->hasBar($asset, '2026-08-25'));
        $this->assertTrue($this->hasBar($asset, '2026-08-26'));
        $this->assertSame(['2026-08-25', '2026-08-26'], app(RunMetadata::class)->get('backfill_sessions'));
    }

    public function test_a_weekend_is_not_mistaken_for_a_missed_session(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingDay('2026-08-21');
        $this->tradingDay('2026-08-22', false);
        $this->tradingDay('2026-08-23', false);
        $this->tradingDay('2026-08-24');
        $this->storedBar($asset, '2026-08-21');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-24', '2026-08-24', null, 1)
            ->andReturn(['data' => $this->bar('2026-08-24')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-24']);

        $this->assertSame(0, app(RunMetadata::class)->get('backfilled_ticker_count'));
    }

    /**
     * An asset listed on Wednesday holds no bar for Monday or Tuesday, and
     * never will. Those are not gaps; re-requesting them every night would be.
     */
    public function test_sessions_before_a_tickers_first_bar_are_not_gaps(): void
    {
        $asset = $this->asset('NEWCO');
        $this->tradingWeek();
        $this->storedBar($asset, '2026-08-26');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('NEWCO', 'HS_PERIOD_DAILY', '2026-08-27', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bar('2026-08-27')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-27']);

        $this->assertSame(0, app(RunMetadata::class)->get('backfilled_ticker_count'));
    }

    public function test_backfill_sessions_zero_fetches_the_target_date_only(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingWeek();
        $this->storedBar($asset, '2026-08-24');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-27', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bar('2026-08-27')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-27', '--backfill-sessions' => 0]);

        $this->assertFalse($this->hasBar($asset, '2026-08-25'));
    }

    /**
     * A current ticker is not dragged into a behind ticker's wider range:
     * each starting date is its own request.
     */
    public function test_tickers_are_grouped_by_where_their_range_starts(): void
    {
        $current = $this->asset('BBCA');
        $behind = $this->asset('BBRI');
        $this->tradingWeek();
        $this->storedBar($current, '2026-08-24');
        $this->storedBar($current, '2026-08-25');
        $this->storedBar($current, '2026-08-26');
        $this->storedBar($behind, '2026-08-24');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-27', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bar('2026-08-27')]);
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2026-08-25', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bars(['2026-08-25', '2026-08-26', '2026-08-27'])]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-27']);

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame(1, $metadata['backfilled_ticker_count']);
        $this->assertSame(2, $metadata['success_ticker_count']);
    }

    /**
     * A recovered session that is still empty came back from the same request
     * that did deliver today's bar, so it is a day the ticker did not trade --
     * a suspension -- not a failed fetch. Reported, but not a partial run:
     * otherwise one suspended stock would flag every run for weeks.
     */
    public function test_a_session_that_stays_empty_is_reported_without_marking_the_run_partial(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingWeek();
        $this->storedBar($asset, '2026-08-24');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        // Tuesday never comes back: suspended.
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-25', '2026-08-27', null, 1)
            ->andReturn(['data' => $this->bars(['2026-08-26', '2026-08-27'])]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-27']);

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame(1, $metadata['backfill_unrecovered_count']);
        $this->assertSame(['BBCA 2026-08-25'], $metadata['backfill_unrecovered']);
        $this->assertFalse($metadata['partial'], 'Today landed; an untraded earlier day is not a failed run.');
        $this->assertTrue($this->hasBar($asset, '2026-08-26'));
    }

    public function test_a_non_trading_day_never_calls_stockbit(): void
    {
        $this->asset('BBCA');
        $this->tradingDay('2026-08-17', false);
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-17']);

        $metadata = app(RunMetadata::class)->all();
        $this->assertTrue($metadata['skipped']);
        $this->assertSame('not_trading_day', $metadata['skip_reason']);
    }

    public function test_a_trading_day_requests_exactly_that_one_day(): void
    {
        $this->asset('BBCA');
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        // The whole point of the daily job: one day, historical, and nothing
        // else. A from/to that drifted would silently re-scrape months.
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-28', '2026-08-28', null, 1)
            ->andReturn(['data' => $this->bar('2026-08-28')]);
        $mock->shouldReceive('marketDetectors')->never();
        // --no-profile-sync: the profile is slow-moving reference data and has
        // no business being re-fetched every afternoon.
        $mock->shouldReceive('tickerProfile')->never();

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('2026-08-28', $metadata['market_date']);
        $this->assertSame(1, $metadata['ticker_count']);
        $this->assertSame(1, $metadata['success_ticker_count']);
        $this->assertSame(0, $metadata['failed_ticker_count']);
    }

    public function test_the_existing_persistence_path_is_used_for_both_the_csv_and_the_database(): void
    {
        $asset = $this->asset('BBCA');
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')->andReturn(['data' => $this->bar('2026-08-28')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $this->assertDatabaseHas('price_bars', [
            'asset_id' => $asset->id,
            'close' => 1050,
        ]);

        $csv = $this->seedDir.'/BBCA.csv';
        $this->assertFileExists($csv);
        // CsvBars writes the seed format (d/m/Y); the point here is that the
        // day landed via the existing writer, not a second one.
        $this->assertStringContainsString('28/08/2026', (string) file_get_contents($csv));
    }

    public function test_only_price_sync_assets_are_targeted(): void
    {
        $this->asset('BBCA', syncPrice: true);
        $this->asset('MUTED', syncPrice: false);
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['data' => $this->bar('2026-08-28')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $this->assertSame(1, app(RunMetadata::class)->get('ticker_count'));
    }

    public function test_a_ticker_that_produced_no_bar_is_reported_rather_than_swallowed(): void
    {
        $this->asset('BBCA');
        $this->asset('BROKEN');
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')
            ->with('BBCA', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['data' => $this->bar('2026-08-28')]);
        $mock->shouldReceive('historicalSummary')
            ->with('BROKEN', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(['error' => 'upstream_error', 'message' => 'gateway timeout']);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $metadata = app(RunMetadata::class)->all();

        $this->assertSame(1, $metadata['success_ticker_count']);
        $this->assertSame(1, $metadata['failed_ticker_count']);
        $this->assertSame(['BROKEN'], $metadata['failed_tickers']);
        $this->assertTrue($metadata['partial'], 'A run that lost a ticker is not a full success.');
        $this->assertStringContainsString('BROKEN', (string) $metadata['error_summary']);
    }

    public function test_the_google_drive_mirror_receives_the_touched_csv(): void
    {
        config(['csv.mirror_disk' => 'gdrive']);

        $this->asset('BBCA');
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')->andReturn(['data' => $this->bar('2026-08-28')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        Storage::disk('gdrive')->assertExists('seeds/historical/BBCA.csv');

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('gdrive', $metadata['gdrive']['disk']);
        $this->assertSame(['BBCA'], $metadata['gdrive']['uploaded']);
        $this->assertSame([], $metadata['gdrive']['failed']);
        // The scrape already mirrored, so the runner must not do it again.
        $this->assertTrue($metadata['mirror_handled']);
    }

    public function test_no_mirror_leaves_cold_storage_untouched(): void
    {
        config(['csv.mirror_disk' => 'gdrive']);

        $this->asset('BBCA');
        $this->tradingDay('2026-08-28');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')->andReturn(['data' => $this->bar('2026-08-28')]);

        Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28', '--no-mirror' => true]);

        Storage::disk('gdrive')->assertMissing('seeds/historical/BBCA.csv');
        // ... and the configured default is restored afterwards.
        $this->assertSame('gdrive', config('csv.mirror_disk'));
    }

    public function test_the_market_date_is_resolved_in_jakarta(): void
    {
        // 23:30 UTC on the 27th is already the 28th in Jakarta.
        Carbon::setTestNow(Carbon::parse('2026-08-27 23:30:00', 'UTC'));

        try {
            $this->asset('BBCA');
            $this->tradingDay('2026-08-28');
            $this->stubProfileUpdater();

            $mock = $this->stockbit();
            $mock->shouldReceive('historicalSummary')
                ->once()
                ->with('BBCA', 'HS_PERIOD_DAILY', '2026-08-28', '2026-08-28', null, 1)
                ->andReturn(['data' => $this->bar('2026-08-28')]);

            Artisan::call('automation:ohlcv-daily');

            $this->assertSame('2026-08-28', app(RunMetadata::class)->get('market_date'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_missing_token_blocks_the_job_before_it_scrapes(): void
    {
        app(StockbitTokenResolver::class)->forget();
        config(['stockbit.bearer' => '']);

        $this->asset('BBCA');
        $this->tradingDay('2026-08-28');

        $mock = $this->stockbit();
        $mock->shouldReceive('historicalSummary')->never();

        $exit = Artisan::call('automation:ohlcv-daily', ['--date' => '2026-08-28']);

        $this->assertSame(1, $exit);
        $this->assertTrue(app(RunMetadata::class)->get('blocked_token'));
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Models\Asset;
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

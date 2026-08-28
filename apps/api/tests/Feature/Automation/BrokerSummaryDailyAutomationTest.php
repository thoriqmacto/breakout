<?php

namespace Tests\Feature\Automation;

use App\Models\Asset;
use App\Models\BrokerSummaryFact;
use App\Models\BrokerSummaryWindow;
use App\Models\TradingCalendarDay;
use App\Services\AssetProfileUpdater;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\TradingWeekResolver;
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
 * The daily job: every asset brought up to the latest *observed* trading day,
 * from wherever it happens to be, without ever asking for a range it already
 * holds.
 */
class BrokerSummaryDailyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-daily-bs-'.bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0755, true);
        @unlink(storage_path('app/bar-csv-mirror.json'));

        config([
            'automation.timezone' => 'Asia/Jakarta',
            'automation.broker_summary_mirror_disk' => null,
            'csv.seed_dir' => $this->seedDir,
            'csv.mirror_disk' => null,
            'stockbit.save_disk' => 'local',
            'stockbit.save_dir' => 'broker_summary',
            'stockbit.defaults.transaction_type' => 'TRANSACTION_TYPE_NET',
            'stockbit.defaults.market_board' => 'MARKET_BOARD_REGULER',
            'stockbit.defaults.investor_type' => 'INVESTOR_TYPE_ALL',
            'stockbit.defaults.limit' => 25,
        ]);

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

        return $encode(['alg' => 'HS256']).'.'.$encode(['exp' => Carbon::now()->addDays(90)->getTimestamp()]).'.sig';
    }

    /**
     * @param  array<int, string>  $tradingDates
     */
    private function calendar(array $tradingDates, string $from = '2026-08-17', string $to = '2026-08-31'): void
    {
        for ($cursor = Carbon::parse($from); $cursor->lessThanOrEqualTo(Carbon::parse($to)); $cursor->addDay()) {
            $date = $cursor->toDateString();

            TradingCalendarDay::updateOrCreate(['date' => $date], [
                'is_trading_day' => in_array($date, $tradingDates, true),
                'is_weekend' => $cursor->dayOfWeekIso >= 6,
                'is_holiday' => $cursor->dayOfWeekIso < 6 && ! in_array($date, $tradingDates, true),
            ]);
        }
    }

    /**
     * Two full trading weeks, 17-21 and 24-28 August, plus Monday 31 August.
     */
    private function twoWeeks(string $to = '2026-08-31'): void
    {
        $this->calendar([
            '2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21',
            '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28',
            '2026-08-31',
        ], '2026-08-17', $to);
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
        $mock->shouldReceive('historicalSummary')->never();
        $this->app->instance(StockbitExodusClient::class, $mock);

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function detectorResponse(string $from, string $to): array
    {
        return [
            'data' => [
                'from' => $from,
                'to' => $to,
                'broker_summary' => [
                    'brokers_buy' => [[
                        'netbs_broker_code' => 'AB',
                        'netbs_date' => $from,
                        'netbs_buy_lot' => 100,
                        'netbs_buy_val' => 1_000_000,
                        'netbs_net_lot' => 80,
                        'netbs_net_val' => 800_000,
                        'bavg' => 10_000,
                    ]],
                    'brokers_sell' => [[
                        'netbs_broker_code' => 'CD',
                        'netbs_date' => $from,
                        'netbs_sell_lot' => 90,
                        'netbs_sell_val' => 900_000,
                        'netbs_net_lot' => -85,
                        'netbs_net_val' => -850_000,
                        'savg' => 10_000,
                    ]],
                ],
            ],
        ];
    }

    private function pathFor(string $symbol, string $from, string $to): string
    {
        return sprintf('broker_summary/%s_%s_%s_TRANSACTION_TYPE_NET.json', $symbol, $from, $to);
    }

    private function storeWindow(Asset $asset, string $from, string $to): BrokerSummaryWindow
    {
        return BrokerSummaryWindow::create([
            'asset_id' => $asset->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
        ]);
    }

    private function runOn(string $instantUtc): void
    {
        Carbon::setTestNow(Carbon::parse($instantUtc, 'UTC'));

        try {
            Artisan::call('automation:broker-summary-daily');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_steady_state_is_one_single_day_window(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->storeWindow($asset, '2026-08-27', '2026-08-27');
        $this->twoWeeks();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-28', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-28', '2026-08-28'));

        $this->runOn('2026-08-28 11:00:00');

        Storage::disk('local')->assertExists($this->pathFor('BBCA', '2026-08-28', '2026-08-28'));

        $window = BrokerSummaryWindow::query()
            ->where('asset_id', $asset->id)
            ->whereDate('to_date', '2026-08-28')
            ->sole();

        // A one-day window is exactly what the day-shaped legacy projections
        // are for, and getting the range right is what lets them be written.
        $this->assertTrue($window->isSingleDay());
        $this->assertGreaterThan(0, BrokerSummaryFact::query()->count());
    }

    public function test_a_gap_is_backfilled_as_one_aggregate_covering_it(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        // The three-month aggregate case: current only to 26 August.
        $this->storeWindow($asset, '2026-05-26', '2026-08-26');
        $this->twoWeeks();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-27', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-27', '2026-08-28'));

        $this->runOn('2026-08-28 11:00:00');

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame([['from' => '2026-08-27', 'to' => '2026-08-28', 'tickers' => 1]], $metadata['ranges']);
        $this->assertSame(1, $metadata['backfilled_ticker_count']);

        // The window it already held is untouched: a new range is a new row.
        $this->assertSame(2, BrokerSummaryWindow::query()->where('asset_id', $asset->id)->count());
    }

    public function test_a_resumed_range_snaps_forward_to_the_next_session(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->storeWindow($asset, '2026-08-28', '2026-08-28');
        $this->twoWeeks();
        $this->stubProfileUpdater();

        // The day after Friday is Saturday. Asking for 29..31 August would
        // return Monday's flow filed as a three-day range, which never reaches
        // the daily projections.
        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-31', '2026-08-31', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-31', '2026-08-31'));

        $this->runOn('2026-08-31 11:00:00');

        $this->assertSame('2026-08-31', app(RunMetadata::class)->get('collect_to'));
    }

    public function test_an_asset_that_is_already_current_is_not_fetched(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->storeWindow($asset, '2026-08-28', '2026-08-28');
        $this->twoWeeks();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->never();

        $this->runOn('2026-08-28 11:00:00');

        $metadata = app(RunMetadata::class)->all();
        $this->assertTrue($metadata['skipped']);
        $this->assertSame('already_up_to_date', $metadata['skip_reason']);
        $this->assertSame(1, $metadata['up_to_date_ticker_count']);
    }

    public function test_an_asset_with_no_history_is_bounded_by_max_backfill_days(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->twoWeeks();
        $this->stubProfileUpdater();

        // 28 August minus five days is 23 August, a Sunday, so the range opens
        // on the Monday session that follows it.
        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-24', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-daily', ['--max-backfill-days' => 5]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, app(RunMetadata::class)->get('backfilled_ticker_count'));
    }

    public function test_assets_at_different_points_are_grouped_into_one_request_each(): void
    {
        $current = Asset::create(['symbol' => 'AAAA', 'name' => 'AAAA']);
        $behind = Asset::create(['symbol' => 'BBBB', 'name' => 'BBBB']);
        $alsoBehind = Asset::create(['symbol' => 'CCCC', 'name' => 'CCCC']);

        $this->storeWindow($current, '2026-08-27', '2026-08-27');
        $this->storeWindow($behind, '2026-08-25', '2026-08-25');
        $this->storeWindow($alsoBehind, '2026-08-25', '2026-08-25');

        $this->twoWeeks();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('AAAA', '2026-08-28', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-28', '2026-08-28'));
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBBB', '2026-08-26', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-26', '2026-08-28'));
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('CCCC', '2026-08-26', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-26', '2026-08-28'));

        $this->runOn('2026-08-28 11:00:00');

        // Two ranges, oldest first, with the two equally-behind tickers sharing
        // a single scrape invocation rather than getting one each.
        $this->assertSame([
            ['from' => '2026-08-26', 'to' => '2026-08-28', 'tickers' => 2],
            ['from' => '2026-08-28', 'to' => '2026-08-28', 'tickers' => 1],
        ], app(RunMetadata::class)->get('ranges'));
    }

    public function test_it_collects_to_the_last_observed_day_when_today_is_unconfirmed(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->storeWindow($asset, '2026-08-26', '2026-08-26');
        // The calendar has not reached Friday yet, which is the normal state
        // before Yahoo publishes the day's bar.
        $this->twoWeeks('2026-08-27');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-27', '2026-08-27', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-27', '2026-08-27'));

        $this->runOn('2026-08-28 11:00:00');

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('2026-08-28', $metadata['market_date']);
        $this->assertSame('2026-08-27', $metadata['collect_to']);
        $this->assertFalse($metadata['today_confirmed']);
        $this->assertSame(1, $metadata['days_behind']);
    }

    public function test_a_calendar_with_no_recent_trading_day_refuses_rather_than_guessing(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->never();

        $this->runOn('2026-08-28 11:00:00');

        $metadata = app(RunMetadata::class)->all();
        $this->assertTrue($metadata['skipped']);
        $this->assertSame(TradingWeekResolver::STATUS_INCOMPLETE, $metadata['skip_reason']);
    }

    public function test_rerunning_the_same_day_converges_instead_of_duplicating(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->storeWindow($asset, '2026-08-27', '2026-08-27');
        $this->twoWeeks();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-28', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-28', '2026-08-28'));

        $this->runOn('2026-08-28 11:00:00');

        $after = BrokerSummaryWindow::query()->where('asset_id', $asset->id)->count();

        // The second pass finds the asset current and makes no further call --
        // the `never()` on a second marketDetectors is implied by `once()`.
        $this->runOn('2026-08-28 12:00:00');

        $this->assertSame($after, BrokerSummaryWindow::query()->where('asset_id', $asset->id)->count());
        $this->assertSame('already_up_to_date', app(RunMetadata::class)->get('skip_reason'));
    }
}

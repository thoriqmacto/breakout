<?php

namespace Tests\Feature\Automation;

use App\Models\Asset;
use App\Models\BrokerSummaryFact;
use App\Models\BrokerSummaryWindow;
use App\Models\Broksum;
use App\Models\TradingCalendarDay;
use App\Services\AssetProfileUpdater;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\TradingWeekResolver;
use App\Services\BrokerSummaryImporter;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The weekly job: the right day, the right range, one aggregate window, a
 * targeted import, and a mirror that reports rather than destroys.
 */
class BrokerSummaryWeeklyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('gdrive');

        $this->seedDir = sys_get_temp_dir().'/breakout-weekly-'.bin2hex(random_bytes(4));
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

        // Comfortably beyond every date these tests travel to. A short
        // expiry here silently turns a scrape into a blocked_token run,
        // because the token is persisted at real "now" while the tests move
        // the clock forward.
        return $encode(['alg' => 'HS256']).'.'.$encode(['exp' => Carbon::now()->addDays(90)->getTimestamp()]).'.sig';
    }

    /**
     * @param  array<int, string>  $tradingDates
     */
    private function calendar(array $tradingDates, string $from = '2026-08-24', string $to = '2026-08-30'): void
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

    private function fullWeek(): void
    {
        $this->calendar(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28']);
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
     * A market-detector response describing one aggregate window.
     *
     * @return array<string, mixed>
     */
    private function detectorResponse(string $from, string $to): array
    {
        return [
            'data' => [
                'from' => $from,
                'to' => $to,
                'broker_summary' => [
                    'brokers_buy' => [
                        [
                            'netbs_broker_code' => 'AB',
                            'netbs_date' => $from,
                            'netbs_buy_lot' => 100,
                            'netbs_buy_val' => 1_000_000,
                            'netbs_net_lot' => 80,
                            'netbs_net_val' => 800_000,
                            'bavg' => 10_000,
                        ],
                    ],
                    'brokers_sell' => [
                        [
                            'netbs_broker_code' => 'CD',
                            'netbs_date' => $from,
                            'netbs_sell_lot' => 90,
                            'netbs_sell_val' => 900_000,
                            'netbs_net_lot' => -85,
                            'netbs_net_val' => -850_000,
                            'savg' => 10_000,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function pathFor(string $symbol, string $from, string $to): string
    {
        return sprintf('broker_summary/%s_%s_%s_TRANSACTION_TYPE_NET.json', $symbol, $from, $to);
    }

    public function test_it_runs_only_on_the_final_trading_day_of_the_week(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->never();

        // Thursday of a week whose Friday trades.
        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $metadata = app(RunMetadata::class)->all();
        $this->assertTrue($metadata['skipped']);
        $this->assertSame('not_last_trading_day_of_week', $metadata['skip_reason']);
    }

    public function test_a_normal_week_is_fetched_as_monday_to_friday(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-24', '2026-08-28', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25)
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame('2026-08-24', $metadata['range_from']);
        $this->assertSame('2026-08-28', $metadata['range_to']);
    }

    public function test_a_monday_holiday_starts_the_range_on_tuesday(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->calendar(['2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28']);
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-25', '2026-08-28', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-25', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        Storage::disk('local')->assertExists($this->pathFor('BBCA', '2026-08-25', '2026-08-28'));
    }

    public function test_a_friday_holiday_ends_the_range_on_thursday_and_runs_that_day(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->calendar(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27']);
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-24', '2026-08-27', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-27'));

        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('2026-08-27', app(RunMetadata::class)->get('range_to'));
    }

    public function test_an_incomplete_calendar_skips_rather_than_guessing(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        // Friday and the weekend have no rows at all.
        $this->calendar(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'], '2026-08-24', '2026-08-27');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->never();

        Carbon::setTestNow(Carbon::parse('2026-08-27 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame(TradingWeekResolver::STATUS_INCOMPLETE, $metadata['skip_reason']);
        $this->assertContains('2026-08-28', $metadata['missing_dates']);
    }

    public function test_the_weekly_response_is_stored_as_one_multi_day_window(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $window = BrokerSummaryWindow::query()->where('asset_id', $asset->id)->sole();

        $this->assertSame('2026-08-24', Carbon::parse($window->from_date)->toDateString());
        $this->assertSame('2026-08-28', Carbon::parse($window->to_date)->toDateString());
        $this->assertFalse($window->isSingleDay());
        $this->assertSame(2, $window->entries()->count());

        // The whole reason the window model exists: a five-day aggregate must
        // never be written into the day-shaped legacy tables as though Monday
        // or Friday were its trade date.
        $this->assertSame(0, BrokerSummaryFact::query()->count());
        $this->assertSame(0, Broksum::query()->count());
    }

    public function test_only_the_files_this_run_produced_are_imported(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        // An unrelated archive file from an earlier era. A full rebuild would
        // pick it up; a targeted import must not.
        Storage::disk('local')->put(
            $this->pathFor('BBCA', '2020-01-06', '2020-01-10'),
            (string) json_encode($this->detectorResponse('2020-01-06', '2020-01-10')),
        );

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, BrokerSummaryWindow::query()->count());
        $this->assertSame(1, app(RunMetadata::class)->get('import')['imported']);

        // The full rebuild path still sees everything, so recovery is intact.
        app(BrokerSummaryImporter::class)->importFromDisk('local', 'broker_summary');
        $this->assertSame(2, BrokerSummaryWindow::query()->where('asset_id', $asset->id)->count());
    }

    public function test_running_the_same_week_twice_converges_instead_of_duplicating(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $window = BrokerSummaryWindow::query()->sole();
        $this->assertSame(2, $window->entries()->count(), 'A re-import replaces entries, it does not append.');
    }

    public function test_assets_with_broker_summary_sync_disabled_are_not_fetched(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA', 'sync_broker_summary' => true]);
        Asset::create(['symbol' => 'MUTED', 'name' => 'MUTED', 'sync_broker_summary' => false]);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, app(RunMetadata::class)->get('ticker_count'));
    }

    public function test_a_holiday_shortened_week_is_caught_up_on_the_next_weeks_first_trading_day(): void
    {
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);

        // Friday 28 August was a holiday, so the week's last trading day was
        // Thursday — which Thursday itself could not know. Monday settles it.
        $this->calendar(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27']);
        $this->calendar(['2026-08-31'], '2026-08-31', '2026-08-31');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2026-08-24', '2026-08-27', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-27'));

        Carbon::setTestNow(Carbon::parse('2026-08-31 11:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $metadata = app(RunMetadata::class)->all();
        $this->assertSame(TradingWeekResolver::MODE_CATCH_UP, $metadata['weekly_mode']);
        $this->assertSame('2026-08-24', $metadata['range_from']);
        $this->assertSame('2026-08-27', $metadata['range_to']);

        $window = BrokerSummaryWindow::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('2026-08-27', Carbon::parse($window->to_date)->toDateString());
    }

    public function test_a_week_already_summarised_is_not_caught_up_again(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);

        $this->calendar(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27']);
        $this->calendar(['2026-08-31'], '2026-08-31', '2026-08-31');
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        // Once for the catch-up, and never again on the second pass.
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->andReturn($this->detectorResponse('2026-08-24', '2026-08-27'));

        Carbon::setTestNow(Carbon::parse('2026-08-31 11:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $metadata = app(RunMetadata::class)->all();
        $this->assertTrue($metadata['skipped']);
        $this->assertSame('week_already_summarised', $metadata['skip_reason']);
        $this->assertSame(1, BrokerSummaryWindow::query()->count());
    }

    public function test_a_normal_friday_still_summarises_the_current_week(): void
    {
        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 11:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(TradingWeekResolver::MODE_CURRENT, app(RunMetadata::class)->get('weekly_mode'));
    }

    public function test_the_weekly_json_is_mirrored_to_google_drive_after_the_import(): void
    {
        config(['automation.broker_summary_mirror_disk' => 'gdrive']);

        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $path = $this->pathFor('BBCA', '2026-08-24', '2026-08-28');

        Storage::disk('gdrive')->assertExists($path);
        $this->assertSame(
            Storage::disk('local')->get($path),
            Storage::disk('gdrive')->get($path),
            'The mirrored copy must be byte-identical to the local one.',
        );

        $summary = app(RunMetadata::class)->get('gdrive_broker_summary');
        $this->assertSame('ok', $summary['status']);
        $this->assertSame(1, $summary['uploaded']);
    }

    public function test_an_unchanged_file_is_not_uploaded_twice(): void
    {
        config(['automation.broker_summary_mirror_disk' => 'gdrive']);

        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            Artisan::call('automation:broker-summary-weekly');
            Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $summary = app(RunMetadata::class)->get('gdrive_broker_summary');
        $this->assertSame(0, $summary['uploaded']);
        $this->assertSame(1, $summary['skipped_unchanged']);
    }

    public function test_a_drive_failure_is_reported_and_the_local_json_survives(): void
    {
        config(['automation.broker_summary_mirror_disk' => 'gdrive']);

        Asset::create(['symbol' => 'BBCA', 'name' => 'BBCA']);
        $this->fullWeek();
        $this->stubProfileUpdater();

        $mock = $this->stockbit();
        $mock->shouldReceive('marketDetectors')->andReturn($this->detectorResponse('2026-08-24', '2026-08-28'));

        // A Drive that refuses every write.
        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('fileExists')->andReturn(false);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('drive_error: permission denied'));
        Storage::set('gdrive', $failing);

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'UTC'));

        try {
            $exit = Artisan::call('automation:broker-summary-weekly');
        } finally {
            Carbon::setTestNow();
        }

        $path = $this->pathFor('BBCA', '2026-08-24', '2026-08-28');

        // The run does not die, the window is imported, and the only good copy
        // of the JSON is still exactly where it was written.
        $this->assertSame(0, $exit);
        $this->assertSame(1, BrokerSummaryWindow::query()->count());
        Storage::disk('local')->assertExists($path);

        $summary = app(RunMetadata::class)->get('gdrive_broker_summary');
        $this->assertSame('failed', $summary['status']);
        $this->assertNotEmpty($summary['failed']);
    }
}

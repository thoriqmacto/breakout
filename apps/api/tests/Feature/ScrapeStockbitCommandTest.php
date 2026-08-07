<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\AssetProfileUpdater;
use App\Services\StockbitExodusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Exercises the current behaviour of the stockbit:scrape command:
 *   --market-detector  → fetch broker net-flow, save broker_summary/*.json
 *   --historical       → fetch OHLCV, save historical/*.json + persist bars
 *   (no flags)         → profile sync only
 *   --eod              → capture the watchlist snapshot
 *
 * Uses DatabaseMigrations (not RefreshDatabase) because several command
 * paths resolve/insert assets via the DB facade, which conflicts with
 * wrapping each test in a transaction while also instance-binding mocks.
 */
class ScrapeStockbitCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stockbit.bearer', 'test-bearer-token');
        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 25);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
        config()->set('stockbit.historical.page', 1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_market_detector_saves_broksum_json(): void
    {
        Storage::fake('local');
        config()->set('stockbit.defaults.limit', 50);

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2024-01-01', '2024-01-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 50)
            ->andReturn([
                'items' => [
                    ['date' => '2024-01-02', 'broker' => 'AB', 'buy_value' => 1000, 'sell_value' => 400],
                ],
            ]);
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/BBCA_2024-01-01_2024-01-05_TRANSACTION_TYPE_NET.json';
        Storage::disk('local')->assertExists($jsonPath);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertSame('AB', $json['items'][0]['broker']);

        $this->assertStringContainsString('Saved Broksum', Artisan::output());
    }

    public function test_market_detector_error_is_reported(): void
    {
        Storage::fake('local');

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->andReturn(['error' => 'http_500', 'message' => 'Server Error']);
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $this->assertSame([], Storage::disk('local')->files('broker_summary'));
        $this->assertStringContainsString('Error for broksum BBRI', Artisan::output());
    }

    public function test_market_detector_paginates_and_merges(): void
    {
        Storage::fake('local');

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('ASII', '2024-03-01', '2024-03-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25)
            ->andReturn([
                'items' => [
                    ['date' => '2024-03-01', 'broker' => 'AA', 'buy_value' => 100, 'sell_value' => 40],
                ],
                'next_page' => 2,
            ]);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('ASII', '2024-03-01', '2024-03-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25, 2)
            ->andReturn([
                'items' => [
                    ['date' => '2024-03-02', 'broker' => 'BB', 'buy_value' => 200, 'sell_value' => 10],
                ],
                'next_page' => null,
            ]);
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Artisan::call('stockbit:scrape', [
            'tickers' => ['ASII'],
            '--from' => '2024-03-01',
            '--to' => '2024-03-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/ASII_2024-03-01_2024-03-05_TRANSACTION_TYPE_NET.json';
        Storage::disk('local')->assertExists($jsonPath);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertCount(2, $json['items']);
        $this->assertNull($json['next_page'] ?? null);
    }

    public function test_historical_persists_bars_to_db_and_csv(): void
    {
        Storage::fake('local');

        $asset = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,90,95,85,92,100\n03/02/2024,120,125,115,122,2000\n");

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-05', null, 1)
            ->andReturn([
                'data' => [
                    'result' => [
                        ['date' => '2024-02-01', 'open' => '100', 'high' => '110', 'low' => '90', 'close' => '105', 'volume' => '1000'],
                        ['date' => '2024-02-04', 'open' => '150', 'high' => '160', 'low' => '140', 'close' => '155', 'volume' => '3000'],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Carbon::setTestNow('2024-02-06 10:00:00');

        try {
            Artisan::call('stockbit:scrape', [
                'tickers' => ['BBRI'],
                '--from' => '2024-02-01',
                '--to' => '2024-02-05',
                '--historical' => true,
                '--no-profile-sync' => true,
            ]);

            Storage::disk('local')->assertExists('historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json');

            $this->assertDatabaseHas('price_bars', [
                'asset_id' => $asset->id,
                'date' => '2024-02-01',
                'close' => 105,
            ]);
            $this->assertDatabaseHas('price_bars', [
                'asset_id' => $asset->id,
                'date' => '2024-02-04',
                'close' => 155,
            ]);

            $csv = explode("\n", trim(File::get($csvPath)));
            $this->assertSame('Date,Open,High,Low,Close,Volume', $csv[0]);
            // Existing rows are preserved and the new dates are merged in (sorted by date).
            $this->assertContains('01/02/2024,100,110,90,105,1000', $csv);
            $this->assertContains('03/02/2024,120,125,115,122,2000', $csv);
            $this->assertContains('04/02/2024,150,160,140,155,3000', $csv);
        } finally {
            Carbon::setTestNow();
            if ($originalCsv !== null) {
                File::put($csvPath, $originalCsv);
            } else {
                File::delete($csvPath);
            }
        }
    }

    public function test_historical_respects_no_persist_option(): void
    {
        Storage::fake('local');

        $asset = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI']);

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,90,95,85,92,100\n");

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-05', null, 1)
            ->andReturn([
                'data' => [
                    'result' => [
                        ['date' => '2024-02-01', 'open' => '100', 'high' => '110', 'low' => '90', 'close' => '105', 'volume' => '1000'],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        try {
            Artisan::call('stockbit:scrape', [
                'tickers' => ['BBRI'],
                '--from' => '2024-02-01',
                '--to' => '2024-02-05',
                '--historical' => true,
                '--no-profile-sync' => true,
                '--no-persist' => true,
            ]);

            Storage::disk('local')->assertExists('historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json');
            $this->assertDatabaseCount('price_bars', 0);
            $this->assertSame("Date,Open,High,Low,Close,Volume\n01/02/2024,90,95,85,92,100\n", File::get($csvPath));
            $this->assertStringContainsString('Skipping historical persistence (--no-persist).', Artisan::output());
        } finally {
            if ($originalCsv !== null) {
                File::put($csvPath, $originalCsv);
            } else {
                File::delete($csvPath);
            }
        }
    }

    public function test_profile_sync_can_be_skipped(): void
    {
        Storage::fake('local');

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBRI', '2024-02-01', '2024-02-02', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25)
            ->andReturn(['items' => []]);
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
            '--from' => '2024-02-01',
            '--to' => '2024-02-02',
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $this->assertStringContainsString('Profile sync skipped for BBRI', Artisan::output());
    }

    public function test_defaults_to_profile_only_when_no_fetch_flag_set(): void
    {
        Storage::fake('local');

        $profilePayload = ['data' => ['profile' => ['name' => 'Example']]];
        $syncedAt = Carbon::parse('2024-02-01 12:00:00');

        $api = $this->makeStockbitMock();
        $api->shouldReceive('marketDetectors')->never();
        $api->shouldReceive('historicalSummary')->never();
        $api->shouldReceive('tickerProfile')
            ->once()
            ->with('BBRI')
            ->andReturn($profilePayload);

        $profileUpdater = Mockery::mock(AssetProfileUpdater::class);
        $profileUpdater->shouldReceive('getIPODate')->withAnyArgs()->andReturnNull()->byDefault();
        $profileUpdater->shouldReceive('applyTickerProfileResponse')
            ->once()
            ->with('BBRI', $profilePayload)
            ->andReturn([
                'ok' => true,
                'asset' => (object) ['profile_synced_at' => $syncedAt],
                'profile' => $profilePayload['data'],
            ]);

        $this->app->instance(StockbitExodusClient::class, $api);
        $this->app->instance(AssetProfileUpdater::class, $profileUpdater);

        Artisan::call('stockbit:scrape', ['tickers' => ['BBRI']]);

        $this->assertStringContainsString('Profile synced for BBRI', Artisan::output());
    }

    public function test_eod_watchlist_snapshot_is_saved(): void
    {
        Storage::fake('local');

        config()->set('stockbit.watchlist.id', 808507);
        config()->set('stockbit.watchlist.query', [
            'page' => 1,
            'limit' => 500,
            'nochart' => 1,
            'setfincol' => 1,
        ]);

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('watchlist')
            ->once()
            ->withArgs(fn ($watchlistId, $query) => (int) $watchlistId === 808507 && is_array($query))
            ->andReturn([
                'data' => [
                    'header_custom' => [
                        ['item_id' => '20894', 'value' => 'Close Price'],
                    ],
                    'result' => [
                        ['symbol' => 'BBRI', 'column' => []],
                    ],
                ],
            ]);
        $mock->shouldReceive('watchlistColumn')
            ->withArgs(fn ($watchlistId, $itemId) => (int) $watchlistId === 808507 && $itemId === '20894')
            ->andReturn([
                'data' => [
                    'item_id' => '20894',
                    'item_name' => 'Close Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,250.00'],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Carbon::setTestNow('2024-02-03 13:00:00');

        try {
            Artisan::call('stockbit:scrape', [
                '--eod' => true,
                '--no-persist' => true,
            ]);

            $path = 'watchlist_eod/808507_2024-02-03.json';
            Storage::disk('local')->assertExists($path);

            $json = json_decode(Storage::disk('local')->get($path), true);
            $this->assertSame('1,250.00', $json['data']['result'][0]['column']['20894']);
            $this->assertSame('Close Price', $json['column_metadata']['20894']['item_name']);
            $this->assertSame(808507, $json['meta']['watchlist_id']);
            $this->assertStringContainsString('Saved watchlist JSON', Artisan::output());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_eod_watchlist_snapshot_respects_no_persist_option(): void
    {
        Storage::fake('local');

        config()->set('stockbit.watchlist.id', 808507);
        config()->set('stockbit.watchlist.query', ['page' => 1, 'limit' => 500]);

        $mock = $this->makeStockbitMock();
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('watchlist')
            ->once()
            ->withArgs(fn ($watchlistId, $query) => (int) $watchlistId === 808507 && is_array($query))
            ->andReturn([
                'data' => [
                    'header_custom' => [['item_id' => '20894', 'value' => 'Close Price']],
                    'result' => [['symbol' => 'BBRI', 'column' => []]],
                ],
            ]);
        $mock->shouldReceive('watchlistColumn')
            ->withArgs(fn ($watchlistId, $itemId) => (int) $watchlistId === 808507 && $itemId === '20894')
            ->andReturn([
                'data' => [
                    'item_id' => '20894',
                    'item_name' => 'Close Price',
                    'results' => [['symbol' => 'BBRI', 'value' => '1,250.00']],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);
        $this->bindStubProfileUpdater();

        Carbon::setTestNow('2024-02-03 13:00:00');

        try {
            Artisan::call('stockbit:scrape', [
                '--eod' => true,
                '--no-persist' => true,
            ]);

            Storage::disk('local')->assertExists('watchlist_eod/808507_2024-02-03.json');
            $this->assertDatabaseCount('price_bars', 0);
            $this->assertStringContainsString('Skipping watchlist persistence (--no-persist).', Artisan::output());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeStockbitMock()
    {
        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('setBearer')->withAnyArgs()->andReturnNull()->byDefault();

        return $mock;
    }

    /**
     * Bind a stub AssetProfileUpdater so `--no-profile-sync` tests don't
     * trigger the real implementation, which calls tickerProfile()
     * internally via getIPODate() when no seeder JSON exists for the symbol.
     */
    private function bindStubProfileUpdater(): void
    {
        $mock = Mockery::mock(AssetProfileUpdater::class);
        $mock->shouldReceive('getIPODate')->withAnyArgs()->andReturnNull()->byDefault();
        $mock->shouldReceive('applyTickerProfileResponse')
            ->withAnyArgs()
            ->andReturn(['ok' => true, 'asset' => (object) ['profile_synced_at' => null], 'profile' => []])
            ->byDefault();

        $this->app->instance(AssetProfileUpdater::class, $mock);
    }
}

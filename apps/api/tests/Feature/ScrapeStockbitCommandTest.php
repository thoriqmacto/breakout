<?php

namespace Tests\Feature;

use App\Services\StockbitExodusClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ScrapeStockbitCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_writes_json_and_csv_outputs(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2024-01-01', '2024-01-05', null, null, null, 50)
            ->andReturn([
                'items' => [
                    [
                        'date' => '2024-01-02',
                        'broker' => 'AB',
                        'buy_value' => 1000,
                        'sell_value' => 400,
                    ],
                ],
            ]);
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBCA', 'HS_PERIOD_DAILY', '2024-01-01', '2024-01-05', 50, 3)
            ->andReturn([
                'data' => [
                    [
                        'date' => '2024-01-02',
                        'open' => 100,
                        'close' => 110,
                    ],
                ],
            ]);
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--limit' => 50,
            '--historical-period' => 'HS_PERIOD_DAILY',
            '--historical-limit' => 50,
            '--historical-page' => 3,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/BBCA_2024-01-01_2024-01-05_TRANSACTION_TYPE_NET.json';
        $historicalPath = 'historical_summary/BBCA_2024-01-01_2024-01-05_HS_PERIOD_DAILY_page3.json';
        $csvPath = 'broker_summary_csv/BBCA_2024-01-01_2024-01-05.csv';

        Storage::disk('local')->assertExists($jsonPath);
        Storage::disk('local')->assertExists($historicalPath);
        Storage::disk('local')->assertExists($csvPath);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertSame('AB', $json['items'][0]['broker']);

        $historical = json_decode(Storage::disk('local')->get($historicalPath), true);
        $this->assertSame(110, $historical['data'][0]['close']);

        $csv = explode("\n", trim(Storage::disk('local')->get($csvPath)));
        $this->assertSame('symbol,date,broker,net_value,buy_value,sell_value', $csv[0]);
        $this->assertSame('BBCA,2024-01-02,AB,600,1000,400', $csv[1]);

        $output = Artisan::output();
        $this->assertStringContainsString('Saved CSV', $output);
        $this->assertStringContainsString('Saved historical JSON', $output);
    }

    public function test_stops_on_error_response(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->andReturn(['error' => 'http_500', 'message' => 'Server Error']);
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--no-csv' => true,
            '--no-profile-sync' => true,
        ]);

        $this->assertSame([], Storage::disk('local')->files('broker_summary'));
        $this->assertStringContainsString('Error for BBRI', Artisan::output());
    }

    public function test_historical_summary_error_is_logged(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->andReturn([
                'items' => [
                    [
                        'date' => '2024-01-02',
                        'broker' => 'CD',
                        'buy_value' => 200,
                        'sell_value' => 300,
                    ],
                ],
            ]);
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('TLKM', 'HS_PERIOD_DAILY', '2024-01-01', '2024-01-05', null, null)
            ->andReturn(['error' => 'http_500', 'message' => 'Server Error']);
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['TLKM'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--no-csv' => true,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/TLKM_2024-01-01_2024-01-05_TRANSACTION_TYPE_NET.json';
        Storage::disk('local')->assertExists($jsonPath);
        Storage::disk('local')->assertMissing('broker_summary/historical_summary/TLKM_2024-01-01_2024-01-05_HS_PERIOD_DAILY.json');

        $this->assertStringContainsString('Historical summary error for TLKM', Artisan::output());
    }

    public function test_profile_sync_can_be_skipped(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBRI', '2024-02-01', '2024-02-02', null, null, null, null)
            ->andReturn(['items' => []]);
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-02', null, null)
            ->andReturn(['data' => []]);
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
            '--from' => '2024-02-01',
            '--to' => '2024-02-02',
            '--historical-period' => 'HS_PERIOD_DAILY',
            '--no-profile-sync' => true,
        ]);

        $this->assertStringContainsString('Profile sync skipped for BBRI', Artisan::output());
    }

    public function test_eod_watchlist_snapshot_is_saved(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.watchlist.id', 808507);
        config()->set('stockbit.watchlist.query', [
            'page' => 1,
            'limit' => 500,
            'nochart' => 1,
            'setfincol' => 1,
        ]);

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('historicalSummary')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('watchlist')
            ->once()
            ->with(808507, [
                'page' => 1,
                'limit' => 500,
                'nochart' => 1,
                'setfincol' => 1,
            ])
            ->andReturn([
                'data' => [
                    'header_custom' => [
                        ['item_id' => '20891', 'value' => 'Open Price'],
                    ],
                    'result' => [
                        ['symbol' => 'BBRI', 'column' => []],
                    ],
                ],
            ]);
        $mock->shouldReceive('watchlistColumn')
            ->once()
            ->with(808507, '20891')
            ->andReturn([
                'data' => [
                    'item_id' => '20891',
                    'item_name' => 'Open Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,234.00'],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);

        Carbon::setTestNow('2024-02-03 13:00:00');

        Artisan::call('stockbit:scrape', [
            '--eod' => true,
        ]);

        Carbon::setTestNow();

        $path = 'watchlist_eod/808507_2024-02-03_13-00-00.json';
        Storage::disk('local')->assertExists($path);

        $json = json_decode(Storage::disk('local')->get($path), true);
        $this->assertSame('1,234.00', $json['data']['result'][0]['column']['20891']);
        $this->assertSame('Open Price', $json['column_metadata']['20891']['item_name']);
        $this->assertSame(808507, $json['meta']['watchlist_id']);
    }
}

<?php

namespace Tests\Feature;

use App\Services\AssetProfileUpdater;
use App\Services\StockbitExodusClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 50);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
        config()->set('stockbit.historical.limit', 50);
        config()->set('stockbit.historical.page', 3);

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBCA', '2024-01-01', '2024-01-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 50, null)
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
            '--market-detector' => true,
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
            '--market-detector' => true,
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
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 25);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('TLKM', '2024-01-01', '2024-01-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25, null)
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
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/TLKM_2024-01-01_2024-01-05_TRANSACTION_TYPE_NET.json';
        Storage::disk('local')->assertExists($jsonPath);
        Storage::disk('local')->assertMissing('historical_summary/TLKM_2024-01-01_2024-01-05_HS_PERIOD_DAILY.json');
        Storage::disk('local')->assertExists('broker_summary_csv/TLKM_2024-01-01_2024-01-05.csv');

        $this->assertStringContainsString('Historical summary error for TLKM', Artisan::output());
    }

    public function test_historical_data_persists_to_db_and_csv(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
        config()->set('stockbit.historical.page', 1);

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,90,95,85,92,100\n03/02/2024,120,125,115,122,2000\n");

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-05', null, 1)
            ->andReturn([
                'data' => [
                    'result' => [
                        [
                            'date' => '2024-02-01',
                            'open' => '100',
                            'high' => '110',
                            'low' => '90',
                            'close' => '105',
                            'volume' => '1000',
                        ],
                        [
                            'date' => '2024-02-04',
                            'open' => '150',
                            'high' => '160',
                            'low' => '140',
                            'close' => '155',
                            'volume' => '3000',
                        ],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);

        $assetQuery = Mockery::mock();
        $assetQuery->shouldReceive('where')->with('symbol', 'BBRI')->andReturnSelf();
        $assetQuery->shouldReceive('value')->with('id')->andReturn(456);
        DB::shouldReceive('table')->with('assets')->andReturn($assetQuery);

        $upsertPayload = [];
        $priceBarsQuery = Mockery::mock();
        $priceBarsQuery->shouldReceive('upsert')
            ->once()
            ->with(
                Mockery::on(function ($rows) use (&$upsertPayload) {
                    $upsertPayload = $rows;

                    return count($rows) === 2
                        && $rows[0]['asset_id'] === 456
                        && $rows[1]['date'] === '2024-02-04';
                }),
                ['asset_id', 'date'],
                ['open', 'high', 'low', 'close', 'volume', 'updated_at']
            );
        DB::shouldReceive('table')->with('price_bars')->andReturn($priceBarsQuery);

        Carbon::setTestNow('2024-02-06 10:00:00');

        try {
            Artisan::call('stockbit:scrape', [
                'tickers' => ['BBRI'],
                '--from' => '2024-02-01',
                '--to' => '2024-02-05',
                '--historical' => true,
                '--no-profile-sync' => true,
            ]);

            $historicalPath = 'historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json';
            Storage::disk('local')->assertExists($historicalPath);

            $this->assertCount(2, $upsertPayload);
            $this->assertSame(100.0, $upsertPayload[0]['open']);
            $this->assertSame(155.0, $upsertPayload[1]['close']);

            $csv = explode("\n", trim(File::get($csvPath)));
            $this->assertSame('Date,Open,High,Low,Close,Volume', $csv[0]);
            $this->assertSame('01/02/2024,100,110,90,105,1000', $csv[1]);
            $this->assertSame('03/02/2024,120,125,115,122,2000', $csv[2]);
            $this->assertSame('04/02/2024,150,160,140,155,3000', $csv[3]);
        } finally {
            Carbon::setTestNow();

            if ($originalCsv !== null) {
                File::put($csvPath, $originalCsv);
            } else {
                File::delete($csvPath);
            }
        }
    }

    public function test_historical_data_respects_no_persist_option(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
        config()->set('stockbit.historical.page', 1);

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,90,95,85,92,100\n");

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')->never();
        $mock->shouldReceive('tickerProfile')->never();
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('BBRI', 'HS_PERIOD_DAILY', '2024-02-01', '2024-02-05', null, 1)
            ->andReturn([
                'data' => [
                    'result' => [
                        [
                            'date' => '2024-02-01',
                            'open' => '100',
                            'high' => '110',
                            'low' => '90',
                            'close' => '105',
                            'volume' => '1000',
                        ],
                    ],
                ],
            ]);

        $this->app->instance(StockbitExodusClient::class, $mock);

        DB::shouldReceive('table')->never();

        try {
            Artisan::call('stockbit:scrape', [
                'tickers' => ['BBRI'],
                '--from' => '2024-02-01',
                '--to' => '2024-02-05',
                '--historical' => true,
                '--no-profile-sync' => true,
                '--no-persist' => true,
            ]);

            $historicalPath = 'historical/BBRI_2024-02-01_2024-02-05_HS_PERIOD_DAILY.json';
            Storage::disk('local')->assertExists($historicalPath);

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

    public function test_fetches_all_paginated_market_detector_pages(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 25);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('ASII', '2024-03-01', '2024-03-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25, null)
            ->andReturn([
                'items' => [
                    [
                        'date' => '2024-03-01',
                        'broker' => 'AA',
                        'buy_value' => 100,
                        'sell_value' => 40,
                    ],
                ],
                'next_page' => 2,
            ]);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('ASII', '2024-03-01', '2024-03-05', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25, 2)
            ->andReturn([
                'items' => [
                    [
                        'date' => '2024-03-02',
                        'broker' => 'BB',
                        'buy_value' => 200,
                        'sell_value' => 10,
                    ],
                ],
                'next_page' => null,
            ]);
        $mock->shouldReceive('historicalSummary')
            ->once()
            ->with('ASII', 'HS_PERIOD_DAILY', '2024-03-01', '2024-03-05', null, null)
            ->andReturn(['data' => []]);
        $mock->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['ASII'],
            '--from' => '2024-03-01',
            '--to' => '2024-03-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $jsonPath = 'broker_summary/ASII_2024-03-01_2024-03-05_TRANSACTION_TYPE_NET.json';
        $csvPath = 'broker_summary_csv/ASII_2024-03-01_2024-03-05.csv';

        Storage::disk('local')->assertExists($jsonPath);
        Storage::disk('local')->assertExists($csvPath);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertCount(2, $json['items']);
        $this->assertNull($json['next_page'] ?? null);

        $csv = explode("\n", trim(Storage::disk('local')->get($csvPath)));
        $this->assertCount(3, $csv);
        $this->assertStringContainsString('ASII,2024-03-01,AA', $csv[1]);
        $this->assertStringContainsString('ASII,2024-03-02,BB', $csv[2]);
    }

    public function test_profile_sync_can_be_skipped(): void
    {
        Storage::fake('local');

        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 25);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');

        $mock = Mockery::mock(StockbitExodusClient::class);
        $mock->shouldReceive('marketDetectors')
            ->once()
            ->with('BBRI', '2024-02-01', '2024-02-02', 'TRANSACTION_TYPE_NET', 'MARKET_BOARD_REGULER', 'INVESTOR_TYPE_ALL', 25, null)
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
            '--market-detector' => true,
            '--no-profile-sync' => true,
        ]);

        $this->assertStringContainsString('Profile sync skipped for BBRI', Artisan::output());
    }

    public function test_defaults_to_profile_only_when_market_detector_flag_not_set(): void
    {
        Storage::fake('local');

        $profilePayload = ['data' => ['profile' => ['name' => 'Example']]];
        $syncedAt = Carbon::parse('2024-02-01 12:00:00');

        $api = Mockery::mock(StockbitExodusClient::class);
        $api->shouldReceive('marketDetectors')->never();
        $api->shouldReceive('historicalSummary')->never();
        $api->shouldReceive('tickerProfile')
            ->once()
            ->with('BBRI')
            ->andReturn($profilePayload);

        $profileUpdater = Mockery::mock(AssetProfileUpdater::class);
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

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
        ]);

        $this->assertStringContainsString('Profile synced for BBRI', Artisan::output());
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

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,100,110,90,105,1000\n");

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
                        ['item_id' => '20892', 'value' => 'High Price'],
                        ['item_id' => '20893', 'value' => 'Low Price'],
                        ['item_id' => '20894', 'value' => 'Close Price'],
                        ['item_id' => '20895', 'value' => 'Volume'],
                    ],
                    'result' => [
                        ['symbol' => 'BBRI', 'column' => []],
                    ],
                ],
            ]);
        $columnResponses = [
            '20891' => [
                'data' => [
                    'item_id' => '20891',
                    'item_name' => 'Open Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,234.00'],
                    ],
                ],
            ],
            '20892' => [
                'data' => [
                    'item_id' => '20892',
                    'item_name' => 'High Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,300.00'],
                    ],
                ],
            ],
            '20893' => [
                'data' => [
                    'item_id' => '20893',
                    'item_name' => 'Low Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,200.00'],
                    ],
                ],
            ],
            '20894' => [
                'data' => [
                    'item_id' => '20894',
                    'item_name' => 'Close Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,250.00'],
                    ],
                ],
            ],
            '20895' => [
                'data' => [
                    'item_id' => '20895',
                    'item_name' => 'Volume',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '5,678,900'],
                    ],
                ],
            ],
        ];
        $mock->shouldReceive('watchlistColumn')
            ->times(count($columnResponses))
            ->withArgs(function ($watchlistId, $itemId) use ($columnResponses) {
                return $watchlistId === 808507 && isset($columnResponses[$itemId]);
            })
            ->andReturnUsing(function ($watchlistId, $itemId) use ($columnResponses) {
                return $columnResponses[$itemId];
            });

        $this->app->instance(StockbitExodusClient::class, $mock);

        try {
            $assetQuery = Mockery::mock();
            $assetQuery->shouldReceive('where')->with('symbol', 'BBRI')->andReturnSelf();
            $assetQuery->shouldReceive('value')->with('id')->andReturn(123);
            DB::shouldReceive('table')->with('assets')->andReturn($assetQuery);

            $upsertPayload = [];
            $priceBarsQuery = Mockery::mock();
            $priceBarsQuery->shouldReceive('upsert')
                ->once()
                ->with(
                    Mockery::on(function ($rows) use (&$upsertPayload) {
                        $upsertPayload = $rows;
                        return isset($rows[0]['asset_id'], $rows[0]['date']);
                    }),
                    ['asset_id', 'date'],
                    ['open', 'high', 'low', 'close', 'volume', 'updated_at']
                );
            DB::shouldReceive('table')->with('price_bars')->andReturn($priceBarsQuery);

            Carbon::setTestNow('2024-02-03 13:00:00');

            Artisan::call('stockbit:scrape', [
                '--eod' => true,
            ]);

            $path = 'watchlist_eod/808507_2024-02-03_13-00-00.json';
            Storage::disk('local')->assertExists($path);

            $json = json_decode(Storage::disk('local')->get($path), true);
            $this->assertSame('1,234.00', $json['data']['result'][0]['column']['20891']);
            $this->assertSame('Open Price', $json['column_metadata']['20891']['item_name']);
            $this->assertSame(808507, $json['meta']['watchlist_id']);

            $csv = explode("\n", trim(File::get($csvPath)));
            $this->assertSame('Date,Open,High,Low,Close,Volume', $csv[0]);
            $this->assertSame('01/02/2024,100,110,90,105,1000', $csv[1]);
            $this->assertSame('03/02/2024,1234.00,1300.00,1200.00,1250.00,5678900', $csv[2]);

            $this->assertCount(1, $upsertPayload);
            $this->assertSame(123, $upsertPayload[0]['asset_id']);
            $this->assertSame('2024-02-03', $upsertPayload[0]['date']);
            $this->assertSame(1234.0, $upsertPayload[0]['open']);
            $this->assertSame(1300.0, $upsertPayload[0]['high']);
            $this->assertSame(1200.0, $upsertPayload[0]['low']);
            $this->assertSame(1250.0, $upsertPayload[0]['close']);
            $this->assertSame(5678900, $upsertPayload[0]['volume']);
        } finally {
            Carbon::setTestNow();

            if ($originalCsv !== null) {
                File::put($csvPath, $originalCsv);
            } else {
                File::delete($csvPath);
            }
        }
    }

    public function test_eod_watchlist_snapshot_respects_no_persist_option(): void
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

        $csvPath = database_path('seeders/data/historical/BBRI.csv');
        $originalCsv = File::exists($csvPath) ? File::get($csvPath) : null;
        File::put($csvPath, "Date,Open,High,Low,Close,Volume\n01/02/2024,100,110,90,105,1000\n");

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
                        ['item_id' => '20892', 'value' => 'High Price'],
                        ['item_id' => '20893', 'value' => 'Low Price'],
                        ['item_id' => '20894', 'value' => 'Close Price'],
                        ['item_id' => '20895', 'value' => 'Volume'],
                    ],
                    'result' => [
                        ['symbol' => 'BBRI', 'column' => []],
                    ],
                ],
            ]);
        $columnResponses = [
            '20891' => [
                'data' => [
                    'item_id' => '20891',
                    'item_name' => 'Open Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,234.00'],
                    ],
                ],
            ],
            '20892' => [
                'data' => [
                    'item_id' => '20892',
                    'item_name' => 'High Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,300.00'],
                    ],
                ],
            ],
            '20893' => [
                'data' => [
                    'item_id' => '20893',
                    'item_name' => 'Low Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,200.00'],
                    ],
                ],
            ],
            '20894' => [
                'data' => [
                    'item_id' => '20894',
                    'item_name' => 'Close Price',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '1,250.00'],
                    ],
                ],
            ],
            '20895' => [
                'data' => [
                    'item_id' => '20895',
                    'item_name' => 'Volume',
                    'results' => [
                        ['symbol' => 'BBRI', 'value' => '5,678,900'],
                    ],
                ],
            ],
        ];
        $mock->shouldReceive('watchlistColumn')
            ->times(count($columnResponses))
            ->withArgs(function ($watchlistId, $itemId) use ($columnResponses) {
                return $watchlistId === 808507 && isset($columnResponses[$itemId]);
            })
            ->andReturnUsing(function ($watchlistId, $itemId) use ($columnResponses) {
                return $columnResponses[$itemId];
            });

        $this->app->instance(StockbitExodusClient::class, $mock);

        try {
            DB::shouldReceive('table')->never();
            Carbon::setTestNow('2024-02-03 13:00:00');

            Artisan::call('stockbit:scrape', [
                '--eod' => true,
                '--no-persist' => true,
            ]);

            $path = 'watchlist_eod/808507_2024-02-03_13-00-00.json';
            Storage::disk('local')->assertExists($path);

            $json = json_decode(Storage::disk('local')->get($path), true);
            $this->assertSame('1,234.00', $json['data']['result'][0]['column']['20891']);
            $this->assertSame('Open Price', $json['column_metadata']['20891']['item_name']);
            $this->assertSame(808507, $json['meta']['watchlist_id']);

            $csv = explode("\n", trim(File::get($csvPath)));
            $this->assertSame('Date,Open,High,Low,Close,Volume', $csv[0]);
            $this->assertSame('01/02/2024,100,110,90,105,1000', $csv[1]);
            $this->assertCount(2, $csv); // no new row added
        } finally {
            Carbon::setTestNow();

            if ($originalCsv !== null) {
                File::put($csvPath, $originalCsv);
            } else {
                File::delete($csvPath);
            }
        }
    }
}

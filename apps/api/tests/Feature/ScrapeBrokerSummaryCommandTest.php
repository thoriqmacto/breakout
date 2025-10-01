<?php

namespace Tests\Feature;

use App\Services\StockbitExodusClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ScrapeBrokerSummaryCommandTest extends TestCase
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

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--limit' => 50,
        ]);

        $jsonPath = 'broker_summary/BBCA_2024-01-01_2024-01-05_TRANSACTION_TYPE_NET.json';
        $csvPath = 'broker_summary_csv/BBCA_2024-01-01_2024-01-05.csv';

        Storage::disk('local')->assertExists($jsonPath);
        Storage::disk('local')->assertExists($csvPath);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertSame('AB', $json['items'][0]['broker']);

        $csv = explode("\n", trim(Storage::disk('local')->get($csvPath)));
        $this->assertSame('symbol,date,broker,net_value,buy_value,sell_value', $csv[0]);
        $this->assertSame('BBCA,2024-01-02,AB,600,1000,400', $csv[1]);

        $this->assertStringContainsString('Saved CSV', Artisan::output());
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

        $this->app->instance(StockbitExodusClient::class, $mock);

        Artisan::call('stockbit:scrape', [
            'tickers' => ['BBRI'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--no-csv' => true,
        ]);

        $this->assertSame([], Storage::disk('local')->files('broker_summary'));
        $this->assertStringContainsString('Error for BBRI', Artisan::output());
    }
}

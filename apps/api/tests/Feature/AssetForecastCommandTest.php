<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AssetForecastCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_forecast_table_for_assets(): void
    {
        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
        ]);

        $rows = [
            ['date' => '2024-01-01', 'open' => 9, 'high' => 10, 'low' => 9, 'close' => 9, 'volume' => 1000],
            ['date' => '2024-01-08', 'open' => 10, 'high' => 11, 'low' => 10, 'close' => 10, 'volume' => 1000],
            ['date' => '2024-01-15', 'open' => 11, 'high' => 12, 'low' => 11, 'close' => 11, 'volume' => 1000],
            ['date' => '2024-01-22', 'open' => 10, 'high' => 11, 'low' => 9.5, 'close' => 10, 'volume' => 1000],
            ['date' => '2024-01-29', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 400],
            ['date' => '2024-01-31', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 600],
            ['date' => '2024-02-05', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 1000],
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1000],
        ];

        foreach ($rows as $row) {
            Price::create($row + ['asset_id' => $asset->id]);
        }

        $this->artisan('asset:forecast', ['--sym' => 'AAA'])
            ->expectsTable(
                [
                    'Ticker',
                    'Last Close',
                    'Last Close Date',
                    'Trigger Price',
                    'Distance %',
                    'Swing Week End',
                    'Volume EMA',
                    'Volume Target (1.2x)',
                    'Note',
                ],
                [[
                    'AAA',
                    '14.0000',
                    '2024-02-16',
                    '15.0000',
                    '7.14%',
                    '2024-02-02',
                    '1,000',
                    '1,200',
                    '',
                ]]
            )
            ->assertExitCode(0);
    }

    public function test_returns_failure_when_no_assets_found(): void
    {
        $exitCode = Artisan::call('asset:forecast', ['--sym' => 'ZZZ']);

        $this->assertSame(1, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Asset ZZZ not found. Skipping.', $output);
        $this->assertStringContainsString('No rows to display.', $output);
    }
}

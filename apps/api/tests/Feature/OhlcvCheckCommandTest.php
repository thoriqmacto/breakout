<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OhlcvCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_inconsistencies_for_symbol(): void
    {
        $asset = Asset::create(['symbol' => 'INCO', 'name' => 'Vale Indonesia']);

        foreach (['2024-01-01', '2024-01-02', '2024-01-03'] as $date) {
            TradingDay::create(['date' => $date]);
        }

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-01',
            'open' => 1,
            'high' => 2,
            'low' => 0.5,
            'close' => 1.5,
            'volume' => 100,
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-03',
            'open' => 2,
            'high' => 3,
            'low' => 1.5,
            'close' => 2.5,
            'volume' => 200,
        ]);

        $this->artisan('ohlcv:check', ['symbol' => 'INCO'])
            ->expectsOutputToContain('Asset INCO (#'.$asset->id.')')
            ->expectsOutputToContain('Missing trading days (1): 2024-01-02')
            ->expectsOutputToContain('Consistency: FAILED')
            ->assertExitCode(1);
    }

    public function test_checks_all_symbols_from_configuration(): void
    {
        $asset = Asset::create(['symbol' => 'INCO', 'name' => 'Vale Indonesia']);
        Asset::create(['symbol' => 'ACES', 'name' => 'Astra Otoparts']);

        $start = Carbon::parse('2024-01-01');
        for ($i = 0; $i < 3; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            TradingDay::create(['date' => $date]);

            Price::create([
                'asset_id' => $asset->id,
                'date' => $date,
                'open' => 1 + $i,
                'high' => 2 + $i,
                'low' => 0.5 + $i,
                'close' => 1.5 + $i,
                'volume' => 100 + $i,
            ]);
        }

        $this->artisan('ohlcv:check', ['--all' => true])
            ->expectsOutputToContain('Asset INCO (#'.$asset->id.')')
            ->expectsOutputToContain('Consistency: PASSED')
            ->assertExitCode(1);
    }
}

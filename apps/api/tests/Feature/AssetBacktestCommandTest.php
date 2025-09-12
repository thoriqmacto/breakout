<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AssetBacktestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_backtest_with_atr_strategy(): void
    {
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $start = Carbon::parse('2024-01-01');
        for ($i = 1; $i <= 40; $i++) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => $start->copy()->addDays($i - 1)->toDateString(),
                'open' => $i,
                'high' => $i + 1,
                'low' => $i - 1,
                'close' => $i,
                'volume' => 1000 + $i,
            ]);
        }

        $exit = Artisan::call('asset:backtest', ['--sym' => 'AAA', '--strategy' => 'AtrBreakout']);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }
}


<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Carbon;

class AssetMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_metrics_for_symbol(): void
    {
        $asset = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);

        $start = Carbon::parse('2024-01-01');
        for ($i = 1; $i <= 66; $i++) {
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

        $this->artisan('asset:metrics', ['--sym' => 'AAA'])
            ->expectsTable(
                ['Symbol', 'Close', 'MA50', 'MA100', '20wH', '55wH', 'ATR14d', 'ROC13', 'IsUptrend?'],
                [['AAA', '66', '42', '34', '67', '67', '2', '6500', 'Yes']]
            )->assertExitCode(0);
    }
}

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
                ['Symbol', 'ATR14', 'MA30', 'High20', 'ROC13', 'Uptrend'],
                [['AAA', '2', '33.5', '67', '6500', 'Yes']]
            )->assertExitCode(0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

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
                ['Symbol', 'Close', 'MA50', 'MA100', '20wH', '55wH', 'ATR14d', 'ROC13', 'AvgVol20', 'Vol/Avg20', 'Close/20wH', 'Close/55wH', 'IsUptrend?', 'Bars'],
                [['AAA', '66', '42', '34', '67', '67', '2', '6500', '1057', '1.01', '0.99', '0.99', 'Yes', '66']]
            )->assertExitCode(0);
    }

    public function test_orders_rows_by_uptrend_ma_and_roc13(): void
    {
        $start = Carbon::parse('2024-01-01');

        $seed = function (string $symbol, array $closes) use ($start) {
            $asset = Asset::create(['symbol' => $symbol, 'name' => 'Asset ' . $symbol]);
            foreach ($closes as $i => $close) {
                Price::create([
                    'asset_id' => $asset->id,
                    'date' => $start->copy()->addDays($i)->toDateString(),
                    'open' => $close,
                    'high' => $close + 1,
                    'low' => $close - 1,
                    'close' => $close,
                    'volume' => 1000 + $i,
                ]);
            }
        };

        $seed('AAA', range(1, 66));
        $seed('AAB', array_merge(range(1, 65), [60]));
        $seed('BBB', array_merge(range(1, 65), [35]));
        $seed('CCC', array_merge(range(100, 36, -1), [65]));
        $seed('DDD', range(66, 1, -1));

        $exitCode = Artisan::call('asset:metrics', ['--sym' => 'AAA,AAB,BBB,CCC,DDD']);
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $order = ['AAA', 'AAB', 'BBB', 'CCC', 'DDD'];
        $positions = array_map(fn($s) => strpos($output, $s), $order);

        foreach ($positions as $pos) {
            $this->assertNotFalse($pos);
        }

        $this->assertLessThan($positions[1], $positions[0]);
        $this->assertLessThan($positions[2], $positions[1]);
        $this->assertLessThan($positions[3], $positions[2]);
        $this->assertLessThan($positions[4], $positions[3]);
    }
}

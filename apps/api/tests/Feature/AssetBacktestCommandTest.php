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

    /**
     * @dataProvider strategyProvider
     */
    public function test_runs_backtest_with_strategy(string $strategy): void
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

        $exit = Artisan::call('asset:backtest', ['--sym' => 'AAA', '--strategy' => $strategy]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }

    public static function strategyProvider(): array
    {
        return [
            ['AtrBreakout'],
            ['DonchianBreakout'],
            ['RocMomentum'],
            ['MovingAverageCrossover'],
            ['RsiReversal'],
            ['SupportResistanceBreakout'],
        ];
    }

    public function test_compares_multiple_strategies(): void
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

        $exit = Artisan::call('asset:backtest', [
            '--sym' => 'AAA',
            '--compare' => true,
            '--strategies' => [
                'AtrBreakout',
                'DonchianBreakout',
                'RocMomentum',
                'MovingAverageCrossover',
                'RsiReversal',
                'SupportResistanceBreakout',
            ],
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('AtrBreakout', $output);
        $this->assertStringContainsString('DonchianBreakout', $output);
        $this->assertStringContainsString('RocMomentum', $output);
        $this->assertStringContainsString('MovingAverageCrossover', $output);
        $this->assertStringContainsString('RsiReversal', $output);
        $this->assertStringContainsString('SupportResistanceBreakout', $output);
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }
}


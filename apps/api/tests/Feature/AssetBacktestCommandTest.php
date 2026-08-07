<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetBacktestCommandTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('strategyProvider')]
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
        $this->assertStringContainsString('Tickers: AAA', $output);
        $this->assertStringContainsString('Ticker: AAA', $output);
        $this->assertStringContainsString('Bars: 40', $output);
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }

    public static function strategyProvider(): array
    {
        return [
            ['AtrBO'],
            ['DonchBO'],
            ['RocMomentum'],
            ['MACross'],
            ['RsiReversal'],
            ['SR_BO'],
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
                'AtrBO',
                'DonchBO',
                'RocMomentum',
                'MACross',
                'RsiReversal',
                'SR_BO',
            ],
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Tickers: AAA', $output);
        $this->assertStringContainsString('Ticker: AAA', $output);
        $this->assertStringContainsString('AtrBO', $output);
        $this->assertStringContainsString('DonchBO', $output);
        $this->assertStringContainsString('RocMomentum', $output);
        $this->assertStringContainsString('MACross', $output);
        $this->assertStringContainsString('RsiReversal', $output);
        $this->assertStringContainsString('SR_BO', $output);
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }

    public function test_runs_backtest_with_trailing_stop(): void
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
            '--strategy' => 'AtrBO',
            '--trailing' => 'percent:0.05',
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }

    public function test_compares_multiple_strategies_with_trailing_stop(): void
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
            '--strategies' => ['AtrBO', 'DonchBO'],
            '--trailing' => 'percent:0.05',
            '--trailing-strategies' => ['AtrBO'],
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Tickers: AAA', $output);
        $this->assertStringContainsString('Ticker: AAA', $output);
        $this->assertStringContainsString('AtrBO', $output);
        $this->assertStringContainsString('DonchBO', $output);
        $this->assertStringContainsString('CAGR', $output);
        $this->assertStringContainsString('Trades', $output);
    }

    public function test_prints_trades_when_option_set(): void
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
            '--trades' => true,
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Tickers: AAA', $output);
        $this->assertStringContainsString('Ticker: AAA', $output);
        $this->assertStringContainsString('Strategy: DonchBO', $output);
        $this->assertStringContainsString('| # | Entry Date', $output);
        $this->assertStringContainsString('Exit Date', $output);
    }

    public function test_runs_hlsl_breakout_scan_with_sym_option(): void
    {
        $assetA = Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
        $assetB = Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);

        $start = Carbon::parse('2024-01-01');

        for ($i = 0; $i < 40; $i++) {
            Price::create([
                'asset_id' => $assetA->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'open' => 10 + $i * 0.1,
                'high' => 10.5 + $i * 0.1,
                'low' => 9.5 + $i * 0.1,
                'close' => 10 + $i * 0.1,
                'volume' => 1000 + $i * 10,
            ]);
        }

        for ($i = 0; $i < 40; $i++) {
            Price::create([
                'asset_id' => $assetB->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'open' => 5 + $i * 0.05,
                'high' => 5.5 + $i * 0.05,
                'low' => 4.5 + $i * 0.05,
                'close' => 5 + $i * 0.05,
                'volume' => 500 + $i * 8,
            ]);
        }

        $exit = Artisan::call('asset:backtest', [
            '--sym' => 'AAA,BBB',
            '--capital' => 100000,
            '--strategy' => 'HLSLBreakout',
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('HLSL Breakout Backtest', $output);
        $this->assertStringContainsString('Tickers: AAA, BBB', $output);
        $this->assertStringContainsString('AAA Bars: 40', $output);
        $this->assertStringContainsString('BBB Bars: 40', $output);
        $this->assertStringContainsString('Final Equity', $output);
    }

    public function test_trades_option_cannot_be_combined_with_compare(): void
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
            '--trades' => true,
        ]);
        $this->assertSame(1, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('The --trades option cannot be combined with --compare.', $output);
    }
}

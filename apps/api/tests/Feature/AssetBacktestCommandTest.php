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
        $this->assertStringContainsString('Strategy: DonchBO', $output);
        $this->assertStringContainsString('| # | Entry Date', $output);
        $this->assertStringContainsString('Exit Date', $output);
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


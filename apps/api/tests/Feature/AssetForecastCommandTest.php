<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\BacktestTrade;
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
                    'Close',
                    'Close Date',
                    'Alert',
                    'Dist%',
                    'Swing Week',
                    'Volume EMA',
                    'Volume Target',
                    'Note',
                ],
                [[
                    'AAA',
                    '14',
                    '2024-02-16',
                    '15',
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

    public function test_all_option_processes_all_assets(): void
    {
        $first = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
        ]);
        $second = Asset::create([
            'symbol' => 'BBB',
            'name' => 'Asset BBB',
        ]);

        foreach ([
            ['asset_id' => $first->id, 'date' => '2024-01-01', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 1_000],
            ['asset_id' => $second->id, 'date' => '2024-01-01', 'open' => 5, 'high' => 6, 'low' => 4, 'close' => 5, 'volume' => 1_000],
        ] as $row) {
            Price::create($row);
        }

        $this->artisan('asset:forecast', ['--all' => true])
            ->expectsOutputToContain('Ticker')
            ->expectsOutputToContain('AAA')
            ->expectsOutputToContain('BBB')
            ->assertExitCode(0);
    }

    public function test_includes_backtest_results_and_trades(): void
    {
        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-01',
            'open' => 10,
            'high' => 11,
            'low' => 9,
            'close' => 10,
            'volume' => 1_000,
        ]);

        Backtest::create([
            'run_id' => 'run-1',
            'created_at' => now(),
            'params_json' => ['symbols' => ['AAA']],
            'stats_json' => [
                'initial_capital' => 100000,
                'final_equity' => 105000,
                'total_return_pct' => 5,
                'CAGR_pct' => 5,
                'max_drawdown_pct' => 2,
                'num_trades' => 1,
                'win_rate_pct' => 100,
                'avg_win_pct' => 5,
                'avg_loss_pct' => 0,
                'profit_factor' => 2,
            ],
        ]);

        BacktestTrade::create([
            'run_id' => 'run-1',
            'asset_id' => $asset->id,
            'entry_date' => '2024-01-10',
            'entry_px' => 10,
            'exit_date' => '2024-01-20',
            'exit_px' => 11,
            'units' => 100,
            'pnl' => 100,
        ]);

        $this->artisan('asset:forecast', [
            '--sym' => 'AAA',
            '--bt-result' => 'AAA',
            '--trades' => true,
        ])
            ->expectsOutputToContain('Backtest Summary for AAA')
            ->expectsTable(
                ['Metric', 'Value'],
                [
                    ['Initial Capital', '100,000.00'],
                    ['Final Equity', '105,000.00'],
                    ['Total Return %', '5.00'],
                    ['CAGR %', '5.00'],
                    ['Max Drawdown %', '2.00'],
                    ['Trades', '1'],
                    ['Win Rate %', '100.00'],
                    ['Avg Win %', '5.00'],
                    ['Avg Loss %', '0.00'],
                    ['Profit Factor', '2.00'],
                ]
            )
            ->expectsOutputToContain('Backtest Trades for AAA')
            ->expectsTable(
                ['#', 'Entry Date', 'Exit Date', 'Entry Price', 'Exit Price', 'Units', 'PnL'],
                [[
                    1,
                    '2024-01-10',
                    '2024-01-20',
                    '10.0000',
                    '11.0000',
                    '100',
                    '100.00',
                ]]
            )
            ->assertExitCode(0);
    }

    public function test_trades_option_requires_backtest_results(): void
    {
        $asset = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Asset AAA',
        ]);

        Price::create([
            'asset_id' => $asset->id,
            'date' => '2024-01-01',
            'open' => 10,
            'high' => 11,
            'low' => 9,
            'close' => 10,
            'volume' => 1_000,
        ]);

        $this->artisan('asset:forecast', [
            '--sym' => 'AAA',
            '--trades' => true,
        ])
            ->expectsOutputToContain('The --trades option requires --bt-result to specify one or more tickers.')
            ->assertExitCode(1);
    }
}

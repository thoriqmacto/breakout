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
                    '#',
                    'Ticker',
                    'Close',
                    'Close Date',
                    'Alert',
                    'Dist%',
                    'Swing Week',
                    'ATR',
                    'Volume EMA',
                    'Volume Target',
                    'Note',
                ],
                [[
                    '1',
                    'AAA',
                    '14',
                    '2024-02-16',
                    '15',
                    '7.14%',
                    '2024-02-02',
                    '2',
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

    public function test_filter_option_requires_all(): void
    {
        $exitCode = Artisan::call('asset:forecast', ['--filter' => 'uptrend']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --filter option requires --all.', Artisan::output());
    }

    public function test_filter_uptrend_shows_only_uptrend_symbols(): void
    {
        $uptrend = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Uptrend Asset',
        ]);

        $downtrend = Asset::create([
            'symbol' => 'BBB',
            'name' => 'Sideways Asset',
        ]);

        $uptrendRows = [
            ['date' => '2024-01-01', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 11, 'high' => 12, 'low' => 10, 'close' => 11, 'volume' => 1_200],
            ['date' => '2024-01-15', 'open' => 12, 'high' => 13, 'low' => 11, 'close' => 12, 'volume' => 1_100],
            ['date' => '2024-01-22', 'open' => 13, 'high' => 14, 'low' => 12, 'close' => 13, 'volume' => 1_050],
            ['date' => '2024-01-29', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 1_000],
            ['date' => '2024-02-05', 'open' => 15, 'high' => 16, 'low' => 14, 'close' => 15, 'volume' => 950],
            ['date' => '2024-02-12', 'open' => 16, 'high' => 17, 'low' => 15, 'close' => 16, 'volume' => 900],
            ['date' => '2024-02-19', 'open' => 17, 'high' => 18, 'low' => 16, 'close' => 17, 'volume' => 850],
        ];

        $downtrendRows = [
            ['date' => '2024-01-01', 'open' => 20, 'high' => 21, 'low' => 19, 'close' => 20, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 19, 'high' => 20, 'low' => 18, 'close' => 19, 'volume' => 1_000],
            ['date' => '2024-01-15', 'open' => 18, 'high' => 19, 'low' => 17, 'close' => 18, 'volume' => 1_000],
            ['date' => '2024-01-22', 'open' => 17, 'high' => 18, 'low' => 16, 'close' => 17, 'volume' => 1_000],
            ['date' => '2024-01-29', 'open' => 16, 'high' => 17, 'low' => 15, 'close' => 16, 'volume' => 1_000],
            ['date' => '2024-02-05', 'open' => 15, 'high' => 16, 'low' => 14, 'close' => 15, 'volume' => 1_000],
            ['date' => '2024-02-12', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 1_000],
            ['date' => '2024-02-19', 'open' => 13, 'high' => 14, 'low' => 12, 'close' => 13, 'volume' => 1_000],
        ];

        foreach ($uptrendRows as $row) {
            Price::create($row + ['asset_id' => $uptrend->id]);
        }

        foreach ($downtrendRows as $row) {
            Price::create($row + ['asset_id' => $downtrend->id]);
        }

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--filter' => 'uptrend',
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('AAA', $output);
        $this->assertStringNotContainsString('BBB', $output);
    }

    public function test_filter_with_alert_shows_only_symbols_with_entry_price(): void
    {
        $withAlert = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Alert Asset',
        ]);

        $withoutAlert = Asset::create([
            'symbol' => 'BBB',
            'name' => 'No Alert Asset',
        ]);

        $withAlertRows = [
            ['date' => '2024-01-01', 'open' => 9, 'high' => 10, 'low' => 9, 'close' => 9, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 10, 'high' => 11, 'low' => 10, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-15', 'open' => 11, 'high' => 12, 'low' => 11, 'close' => 11, 'volume' => 1_000],
            ['date' => '2024-01-22', 'open' => 10, 'high' => 11, 'low' => 9.5, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-29', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 400],
            ['date' => '2024-01-31', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 600],
            ['date' => '2024-02-05', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 1_000],
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1_000],
        ];

        $withoutAlertRows = [
            ['date' => '2024-01-01', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 11, 'high' => 12, 'low' => 10, 'close' => 11, 'volume' => 1_000],
            ['date' => '2024-01-15', 'open' => 12, 'high' => 13, 'low' => 11, 'close' => 12, 'volume' => 1_000],
            ['date' => '2024-01-22', 'open' => 13, 'high' => 14, 'low' => 12, 'close' => 13, 'volume' => 1_000],
        ];

        foreach ($withAlertRows as $row) {
            Price::create($row + ['asset_id' => $withAlert->id]);
        }

        foreach ($withoutAlertRows as $row) {
            Price::create($row + ['asset_id' => $withoutAlert->id]);
        }

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--filter' => 'withAlert',
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('AAA', $output);
        $this->assertStringNotContainsString('BBB', $output);
    }

    public function test_sort_option_requires_all(): void
    {
        $exitCode = Artisan::call('asset:forecast', [
            '--sort' => 'ticker,asc',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --sort option requires --all.', Artisan::output());
    }

    public function test_sort_option_rejects_unknown_column(): void
    {
        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--sort' => 'unknown,asc',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported sort column: unknown.', Artisan::output());
    }

    public function test_sort_by_close_descending_orders_rows(): void
    {
        $first = Asset::create([
            'symbol' => 'AAA',
            'name' => 'First Asset',
        ]);

        $second = Asset::create([
            'symbol' => 'BBB',
            'name' => 'Second Asset',
        ]);

        $this->seedPrices($first, $this->examplePriceRows(14.0));
        $this->seedPrices($second, $this->examplePriceRows(10.0));

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--sort' => 'close,desc',
        ]);

        $this->assertSame(0, $exitCode);
        $tickers = $this->extractTickersFromTable(Artisan::output());
        $this->assertSame(['AAA', 'BBB'], $tickers);
    }

    public function test_sort_by_dist_percent_ascending_orders_rows(): void
    {
        $first = Asset::create([
            'symbol' => 'AAA',
            'name' => 'First Asset',
        ]);

        $second = Asset::create([
            'symbol' => 'BBB',
            'name' => 'Second Asset',
        ]);

        $this->seedPrices($first, $this->examplePriceRows(14.0));
        $this->seedPrices($second, $this->examplePriceRows(10.0));

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--sort' => 'distPercent,asc',
        ]);

        $this->assertSame(0, $exitCode);
        $tickers = $this->extractTickersFromTable(Artisan::output());
        $this->assertSame(['AAA', 'BBB'], $tickers);
    }

    public function test_sort_can_be_combined_with_filter(): void
    {
        $uptrend = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Uptrend Asset',
        ]);

        $downtrend = Asset::create([
            'symbol' => 'BBB',
            'name' => 'Sideways Asset',
        ]);

        $this->seedPrices($uptrend, $this->examplePriceRows(14.0));
        $this->seedPrices($downtrend, $this->examplePriceRows(10.0));

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--filter' => 'uptrend',
            '--sort' => 'ticker,asc',
        ]);

        $this->assertSame(0, $exitCode);
        $tickers = $this->extractTickersFromTable(Artisan::output());
        $this->assertSame(['AAA'], $tickers);
    }

    public function test_filter_combination_applies_all_filters(): void
    {
        $matching = Asset::create([
            'symbol' => 'AAA',
            'name' => 'Matching Asset',
        ]);

        $nonUptrend = Asset::create([
            'symbol' => 'BBB',
            'name' => 'With Alert But Not Uptrend',
        ]);

        $matchingRows = [
            ['date' => '2024-01-01', 'open' => 9, 'high' => 10, 'low' => 9, 'close' => 9, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 10, 'high' => 11, 'low' => 10, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-15', 'open' => 11, 'high' => 12, 'low' => 11, 'close' => 11, 'volume' => 1_000],
            ['date' => '2024-01-22', 'open' => 10, 'high' => 11, 'low' => 9.5, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-29', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 400],
            ['date' => '2024-01-31', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 600],
            ['date' => '2024-02-05', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 1_000],
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1_000],
        ];

        $nonUptrendRows = [
            ['date' => '2024-01-05', 'open' => 10, 'high' => 11, 'low' => 9, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-12', 'open' => 11, 'high' => 12, 'low' => 10.5, 'close' => 11, 'volume' => 1_000],
            ['date' => '2024-01-19', 'open' => 12, 'high' => 13, 'low' => 11.5, 'close' => 12, 'volume' => 1_000],
            ['date' => '2024-01-26', 'open' => 13, 'high' => 14, 'low' => 12.5, 'close' => 13, 'volume' => 1_000],
            ['date' => '2024-02-02', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 1_000],
            ['date' => '2024-02-09', 'open' => 13, 'high' => 13.5, 'low' => 8.5, 'close' => 9, 'volume' => 1_000],
        ];

        foreach ($matchingRows as $row) {
            Price::create($row + ['asset_id' => $matching->id]);
        }

        foreach ($nonUptrendRows as $row) {
            Price::create($row + ['asset_id' => $nonUptrend->id]);
        }

        $exitCode = Artisan::call('asset:forecast', [
            '--all' => true,
            '--filter' => 'uptrend,withAlert',
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('AAA', $output);
        $this->assertStringNotContainsString('BBB', $output);
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
            '--bt-result' => true,
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
                ['#', 'Entry Date', 'Exit Date', 'Entry', 'Exit', 'Units', 'PnL'],
                [[
                    1,
                    '2024-01-10',
                    '2024-01-20',
                    '10',
                    '11',
                    '100',
                    '100',
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
            ->expectsOutputToContain('The --trades option requires --bt-result.')
            ->assertExitCode(1);
    }

    public function test_auto_generates_backtest_when_missing(): void
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
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1500],
        ];

        foreach ($rows as $row) {
            Price::create($row + ['asset_id' => $asset->id]);
        }

        $this->artisan('asset:forecast', [
            '--sym' => 'AAA',
            '--bt-result' => true,
        ])
            ->expectsOutputToContain('Generating HLSLBreakout backtest for AAA...')
            ->expectsOutputToContain('Backtest Summary for AAA')
            ->expectsOutputToContain('Run ID: auto-hlsl-')
            ->assertExitCode(0);

        $this->assertSame(1, Backtest::count());
        $backtest = Backtest::first();
        $this->assertSame(['AAA'], $backtest->params_json['symbols'] ?? []);
        $this->assertSame('HLSLBreakout', $backtest->params_json['strategy'] ?? null);
        $this->assertSame(10_000_000.0, (float) ($backtest->stats_json['initial_capital'] ?? 0.0));
    }

    public function test_rerun_option_requires_backtest_results(): void
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
            '--rerun' => true,
        ])
            ->expectsOutputToContain('The --rerun option requires --bt-result.')
            ->assertExitCode(1);
    }

    public function test_uses_existing_backtest_when_available(): void
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
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1500],
        ];

        foreach ($rows as $row) {
            Price::create($row + ['asset_id' => $asset->id]);
        }

        Backtest::create([
            'run_id' => 'run-1',
            'created_at' => now()->subDay(),
            'params_json' => ['symbols' => ['AAA']],
            'stats_json' => [
                'initial_capital' => 100000,
                'final_equity' => 105000,
                'total_return_pct' => 5,
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

        $exitCode = Artisan::call('asset:forecast', [
            '--sym' => 'AAA',
            '--bt-result' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Backtest Summary for AAA', $output);
        $this->assertStringContainsString('Run ID: run-1', $output);
        $this->assertStringNotContainsString('Generating HLSLBreakout backtest for AAA...', $output);
        $this->assertSame(1, Backtest::count());
    }

    public function test_rerun_option_generates_new_backtest(): void
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
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => 14, 'volume' => 1500],
        ];

        foreach ($rows as $row) {
            Price::create($row + ['asset_id' => $asset->id]);
        }

        Backtest::create([
            'run_id' => 'run-1',
            'created_at' => now()->subDay(),
            'params_json' => ['symbols' => ['AAA']],
            'stats_json' => [
                'initial_capital' => 100000,
                'final_equity' => 105000,
                'total_return_pct' => 5,
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

        $exitCode = Artisan::call('asset:forecast', [
            '--sym' => 'AAA',
            '--bt-result' => true,
            '--rerun' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Generating HLSLBreakout backtest for AAA...', $output);
        $this->assertMatchesRegularExpression('/Run ID: auto-hlsl-[\w-]+/', $output);
        $this->assertSame(2, Backtest::count());
        $this->assertSame(2, BacktestTrade::query()->distinct('run_id')->count('run_id'));
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}>
     */
    private function examplePriceRows(float $finalClose): array
    {
        return [
            ['date' => '2024-01-01', 'open' => 9, 'high' => 10, 'low' => 9, 'close' => 9, 'volume' => 1_000],
            ['date' => '2024-01-08', 'open' => 10, 'high' => 11, 'low' => 10, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-15', 'open' => 11, 'high' => 12, 'low' => 11, 'close' => 11, 'volume' => 1_000],
            ['date' => '2024-01-22', 'open' => 10, 'high' => 11, 'low' => 9.5, 'close' => 10, 'volume' => 1_000],
            ['date' => '2024-01-29', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 400],
            ['date' => '2024-01-31', 'open' => 14, 'high' => 15, 'low' => 13, 'close' => 14, 'volume' => 600],
            ['date' => '2024-02-05', 'open' => 12, 'high' => 13, 'low' => 12, 'close' => 12, 'volume' => 1_000],
            ['date' => '2024-02-16', 'open' => 13, 'high' => 14, 'low' => 13, 'close' => $finalClose, 'volume' => 1_000],
        ];
    }

    /**
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float, volume:int}> $rows
     */
    private function seedPrices(Asset $asset, array $rows): void
    {
        foreach ($rows as $row) {
            Price::create($row + ['asset_id' => $asset->id]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractTickersFromTable(string $output): array
    {
        preg_match_all('/\|\s*\d+\s*\|\s*([A-Z0-9._-]+)\s*\|/', $output, $matches);

        return $matches[1] ?? [];
    }
}

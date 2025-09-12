<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Services\Backtest\DonchianBacktester;
use Illuminate\Support\Carbon;

class AssetBacktest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset:backtest {--sym=} {--strategy=DonchianBreakout} {--capital=100000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a backtest for a given asset symbol and strategy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $symbol = strtoupper((string) $this->option('sym'));
        if ($symbol === '') {
            $this->error('Symbol must be provided via --sym option.');
            return Command::FAILURE;
        }

        $asset = Asset::where('symbol', $symbol)->first();
        if (!$asset) {
            $this->error("Asset {$symbol} not found.");
            return Command::FAILURE;
        }

        $prices = $asset->prices()->orderBy('date')->get(['date','open','high','low','close']);
        if ($prices->isEmpty()) {
            $this->error('No price data available for this asset.');
            return Command::FAILURE;
        }

        $bars = $prices->map(fn($p) => [
            'date'  => $p->date->toDateString(),
            'open'  => (float) $p->open,
            'high'  => (float) $p->high,
            'low'   => (float) $p->low,
            'close' => (float) $p->close,
        ])->all();

        $strategy = (string) $this->option('strategy');
        switch ($strategy) {
            case 'DonchianBreakout':
                $backtester = new DonchianBacktester();
                break;
            default:
                $this->error("Unknown strategy: {$strategy}");
                return Command::FAILURE;
        }

        $capital = (float) $this->option('capital');
        $result = $backtester->run($bars, $capital);

        $metrics = $this->calculateMetrics($bars, $result['equity_curve'], $result['trades'], $capital, $result['final_equity']);

        $rows = [
            ['CAGR', $metrics['cagr']],
            ['MaxDD', $metrics['maxdd']],
            ['Sharpe', $metrics['sharpe']],
            ['Win-rate', $metrics['winrate']],
            ['Profit Factor', $metrics['profit_factor']],
            ['Trades', (string) $metrics['trades']],
        ];

        $this->table(['Metric', 'Value'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array{date:string, open:float, high:float, low:float, close:float}> $bars
     * @param array<int, array{date:string, equity:float}> $curve
     * @param array<int, array{entry_date:string, exit_date:string, entry_price:float, exit_price:float, shares:int, pnl:float}> $trades
     * @return array<string, string|int>
     */
    private function calculateMetrics(array $bars, array $curve, array $trades, float $capital, float $final): array
    {
        $start = Carbon::parse($bars[0]['date']);
        $end   = Carbon::parse($bars[count($bars)-1]['date']);
        $years = max(1e-9, $start->diffInDays($end) / 365);
        $cagr  = ($final / $capital) ** (1 / $years) - 1;

        $peak = $curve[0]['equity'];
        $maxDD = 0.0;
        foreach ($curve as $pt) {
            $peak = max($peak, $pt['equity']);
            $dd = ($pt['equity'] - $peak) / $peak;
            $maxDD = min($maxDD, $dd);
        }
        $maxDD = abs($maxDD);

        $returns = [];
        $prev = $curve[0]['equity'];
        for ($i = 1; $i < count($curve); $i++) {
            $eq = $curve[$i]['equity'];
            $returns[] = ($eq - $prev) / $prev;
            $prev = $eq;
        }
        $avg = $returns === [] ? 0.0 : array_sum($returns) / count($returns);
        $std = 0.0;
        if ($returns !== []) {
            $variance = array_sum(array_map(fn($r) => ($r - $avg) ** 2, $returns)) / count($returns);
            $std = sqrt($variance);
        }
        $sharpe = $std > 0 ? $avg / $std * sqrt(252) : 0.0;

        $wins = 0; $gain = 0; $loss = 0;
        foreach ($trades as $t) {
            $pnl = $t['pnl'];
            if ($pnl > 0) { $wins++; $gain += $pnl; }
            else { $loss += abs($pnl); }
        }
        $tradeCount = count($trades);
        $winRate = $tradeCount > 0 ? $wins / $tradeCount : 0.0;
        $profitFactor = $loss > 0 ? $gain / $loss : ($gain > 0 ? INF : 0.0);

        return [
            'cagr' => sprintf('%.2f%%', $cagr * 100),
            'maxdd' => sprintf('%.2f%%', $maxDD * 100),
            'sharpe' => sprintf('%.2f', $sharpe),
            'winrate' => sprintf('%.2f%%', $winRate * 100),
            'profit_factor' => is_infinite($profitFactor) ? 'Inf' : sprintf('%.2f', $profitFactor),
            'trades' => $tradeCount,
        ];
    }
}

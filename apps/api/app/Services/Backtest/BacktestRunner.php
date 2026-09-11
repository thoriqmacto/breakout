<?php

namespace App\Services\Backtest;

use App\Models\Asset;
use App\Models\Backtest;
use App\Models\BacktestTrade;
use App\Services\AssetMetrics;
use App\Services\Strategies\StrategyCatalogue;
use App\Services\Strategies\TrailingStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One backtest, however it was asked for.
 *
 * The run loop used to live inside `asset:backtest`, which meant a backtest
 * existed only as terminal output: nothing to link to, nothing to compare
 * against last week's parameters, and nothing the dashboard could show. The
 * command is now a caller like any other -- the same arrangement the technical
 * snapshot already has, where the CLI, the API and the scheduler share one
 * implementation rather than three that drift.
 *
 * Every run persists, including a run that finds nothing. A strategy that
 * produced no trades over two years is a result, and a table that only keeps
 * the interesting ones cannot be used to compare strategies honestly.
 */
class BacktestRunner
{
    public function __construct(private readonly StrategyCatalogue $catalogue) {}

    /**
     * Run one strategy over one symbol and store what happened.
     *
     * @param  array<string, mixed>  $options
     *                                         - capital: starting capital (default 3,000,000)
     *                                         - from / to: ISO dates bounding the bars used
     *                                         - trailing: ['type' => 'percent'|'atr', ...]
     *                                         - source: who asked (cli, api, automation)
     *                                         - notes: free text stored with the run
     */
    public function run(string $symbol, string $strategyKey, array $options = []): Backtest
    {
        $symbol = strtoupper(trim($symbol));
        $entry = $this->catalogue->find($strategyKey);

        if ($entry === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown strategy "%s". Available: %s',
                $strategyKey,
                implode(', ', $this->catalogue->keys()),
            ));
        }

        $key = (string) $entry['key'];
        $class = (string) $entry['class'];

        $asset = Asset::query()->where('symbol', $symbol)->first();

        if ($asset === null) {
            throw new InvalidArgumentException(sprintf('No asset found for symbol "%s".', $symbol));
        }

        $capital = max(1.0, (float) ($options['capital'] ?? 3_000_000));
        $from = $this->dateOption($options['from'] ?? null);
        $to = $this->dateOption($options['to'] ?? null);

        $bars = $this->loadBars($asset, $from, $to);

        // Two bars cannot produce an entry and an exit, and calculateMetrics()
        // divides by the span. Refusing here names the reason; letting it
        // through would produce a row full of zeroes that reads like a
        // strategy finding nothing.
        if (count($bars) < 2) {
            throw new InvalidArgumentException(sprintf(
                'Not enough price history for %s in that range (%d bar(s)).',
                $symbol,
                count($bars),
            ));
        }

        $strategy = $this->buildStrategy($class, $bars, $this->trailingStop($options['trailing'] ?? null));

        $backtester = new GenericBacktester($strategy);
        $result = $backtester->run($bars, $capital);
        $stats = $backtester->calculateMetrics(
            $bars,
            $result['equity_curve'],
            $result['trades'],
            $capital,
            $result['final_equity'],
        );

        return $this->persist($asset, $key, $capital, $bars, $result, $stats, $options);
    }

    /**
     * Every built-in strategy over one symbol, for comparison.
     *
     * Each is a run of its own rather than a combined document: the comparison
     * view then reads the same rows the single runs write, and a strategy
     * compared today is the strategy run today.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, Backtest>
     */
    public function compare(string $symbol, array $options = []): array
    {
        $runs = [];

        foreach ($this->catalogue->all() as $entry) {
            // A strategy that emits no daily signal has nothing to backtest on
            // a daily series; including it would report a flat equity curve as
            // though the strategy had been tried and failed.
            if (($entry['emits_daily_signal'] ?? true) === false) {
                continue;
            }

            $runs[(string) $entry['key']] = $this->run($symbol, (string) $entry['key'], $options);
        }

        return $runs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $options
     */
    private function persist(
        Asset $asset,
        string $strategyKey,
        float $capital,
        array $bars,
        array $result,
        array $stats,
        array $options,
    ): Backtest {
        $runId = (string) Str::uuid();

        // One transaction: a run row without its trades would be a result
        // claiming a win rate it cannot evidence.
        return DB::transaction(function () use ($runId, $asset, $strategyKey, $capital, $bars, $result, $stats, $options): Backtest {
            $backtest = Backtest::create([
                'run_id' => $runId,
                'created_at' => Carbon::now(),
                'asset_id' => $asset->id,
                'symbol' => $asset->symbol,
                'strategy' => $strategyKey,
                'source' => (string) ($options['source'] ?? 'api'),
                'params_json' => [
                    'capital' => $capital,
                    'from' => $bars[0]['date'],
                    'to' => $bars[count($bars) - 1]['date'],
                    'bars' => count($bars),
                    'trailing' => $options['trailing'] ?? null,
                ],
                'stats_json' => $this->storableStats(array_merge($stats, [
                    'final_equity' => $result['final_equity'],
                    'return_pct' => $capital > 0
                        ? (($result['final_equity'] - $capital) / $capital) * 100
                        : 0.0,
                ])),
                'notes' => isset($options['notes']) ? (string) $options['notes'] : null,
            ]);

            foreach ($result['trades'] as $trade) {
                BacktestTrade::create([
                    'run_id' => $runId,
                    'asset_id' => $asset->id,
                    'entry_date' => $trade['entry_date'],
                    'entry_px' => $trade['entry_price'],
                    'exit_date' => $trade['exit_date'] !== '' ? $trade['exit_date'] : null,
                    'exit_px' => $trade['exit_price'],
                    'units' => $trade['shares'],
                    'pnl' => $trade['pnl'],
                ]);
            }

            return $backtest;
        });
    }

    /**
     * Make the statistics storable without lying about them.
     *
     * `calculateMetrics()` returns INF for the profit factor of a strategy
     * that won every trade -- gain over a zero loss -- which is the honest
     * arithmetic answer and which JSON cannot encode. Printing it was fine;
     * storing it throws.
     *
     * Zero would be the worst substitute, since it reads as "every trade
     * lost", the exact opposite. So the value becomes null and the key is
     * listed in `unbounded`: null alone would say "unknown", and the
     * difference between "we could not measure this" and "there were no
     * losses to divide by" is the whole point of the number.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function storableStats(array $stats): array
    {
        $unbounded = [];

        foreach ($stats as $key => $value) {
            if (is_float($value) && ! is_finite($value)) {
                $unbounded[] = $key;
                $stats[$key] = null;
            }
        }

        $stats['unbounded'] = $unbounded;

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function buildStrategy(string $class, array $bars, ?TrailingStop $trailingStop): object
    {
        $seed = new AssetMetrics([$bars[0]]);

        return $trailingStop !== null
            ? new $class($seed, trailingStop: $trailingStop)
            : new $class($seed);
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function loadBars(Asset $asset, ?string $from, ?string $to): array
    {
        $query = $asset->prices()->orderBy('date');

        if ($from !== null) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('date', '<=', $to);
        }

        return $query->get(['date', 'open', 'high', 'low', 'close'])
            ->map(static function ($price): array {
                $date = $price->date instanceof \DateTimeInterface
                    ? $price->date->format('Y-m-d')
                    : (string) $price->date;

                return [
                    'date' => $date,
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $trailing
     */
    private function trailingStop(?array $trailing): ?TrailingStop
    {
        if ($trailing === null || ($trailing['type'] ?? null) === null) {
            return null;
        }

        return match ($trailing['type']) {
            'percent' => TrailingStop::percent((float) ($trailing['percent'] ?? 0)),
            'atr' => TrailingStop::atr(
                (float) ($trailing['multiple'] ?? 0),
                (int) ($trailing['period'] ?? 14),
            ),
            default => throw new InvalidArgumentException(
                'A trailing stop is either percent or atr.'
            ),
        };
    }

    private function dateOption(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}

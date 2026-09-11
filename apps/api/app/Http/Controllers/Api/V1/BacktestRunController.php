<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\Backtest;
use App\Services\Backtest\BacktestRunner;
use App\Services\Strategies\StrategyCatalogue;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Backtests from the dashboard, stored like the ones from the terminal.
 *
 * Synchronous on purpose. A single symbol over a couple of years runs in tens
 * of milliseconds, and the reason this exists is to let someone change a
 * parameter and look again -- a queued run would mean polling for an answer
 * that was ready before the poll, and it would put the feature behind a queue
 * worker for no gain.
 *
 * The comparison endpoint reads the same rows a single run writes rather than
 * computing its own: a strategy compared is a strategy that was actually run,
 * and one that was never run is absent rather than zero.
 */
class BacktestRunController extends ApiController
{
    public function __construct(
        private readonly BacktestRunner $runner,
        private readonly StrategyCatalogue $catalogue,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'strategy' => ['required_without:compare', 'string', 'max:64'],
            'compare' => ['sometimes', 'boolean'],
            'capital' => ['sometimes', 'numeric', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'trailing.type' => ['sometimes', 'nullable', 'in:percent,atr'],
            'trailing.percent' => ['sometimes', 'numeric', 'min:0'],
            'trailing.multiple' => ['sometimes', 'numeric', 'min:0'],
            'trailing.period' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $options = [
            'capital' => $data['capital'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'trailing' => $data['trailing'] ?? null,
            'notes' => $data['notes'] ?? null,
            'source' => 'api',
        ];

        // Nulls dropped so the runner's own defaults apply rather than being
        // overwritten with "no value".
        $options = array_filter($options, static fn ($value): bool => $value !== null);
        $options['source'] = 'api';

        try {
            if ($data['compare'] ?? false) {
                $runs = $this->runner->compare($data['symbol'], $options);

                return ApiResponse::success([
                    'runs' => array_map(fn (Backtest $run): array => $this->present($run), array_values($runs)),
                ], 'Backtests complete.', 201);
            }

            $run = $this->runner->run($data['symbol'], (string) $data['strategy'], $options);
        } catch (InvalidArgumentException $exception) {
            // The runner refuses a symbol it has no history for, a range with
            // too few bars, and a strategy that does not exist. All three are
            // the caller's to fix and none is a server fault.
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            ['run' => $this->present($run, withTrades: true)],
            'Backtest complete.',
            201,
        );
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:32'],
            'strategy' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Backtest::query()
            // Rows written by the forecasting command predate these columns and
            // describe something else; without this the history list would mix
            // two kinds of run under one heading.
            ->whereNotNull('strategy')
            ->orderByDesc('created_at');

        if (isset($data['symbol'])) {
            $query->where('symbol', strtoupper($data['symbol']));
        }

        if (isset($data['strategy'])) {
            $query->where('strategy', $data['strategy']);
        }

        $runs = $query->limit($data['limit'] ?? 50)->get();

        return ApiResponse::success([
            'runs' => $runs->map(fn (Backtest $run): array => $this->present($run))->all(),
        ]);
    }

    public function show(string $run)
    {
        $backtest = Backtest::query()->where('run_id', $run)->first();

        if ($backtest === null) {
            return ApiResponse::error('Backtest not found.', 404);
        }

        return ApiResponse::success(['run' => $this->present($backtest, withTrades: true)]);
    }

    /**
     * The latest run per strategy for one symbol.
     *
     * Reads stored runs rather than running anything: a comparison built by
     * re-running on every page load would be slow, and would quietly answer a
     * different question -- "how would these do today" rather than "how did
     * these do when I ran them".
     */
    public function comparison(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
        ]);

        $symbol = strtoupper($data['symbol']);

        $runs = Backtest::query()
            ->where('symbol', $symbol)
            ->whereNotNull('strategy')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('strategy')
            ->map(fn ($group) => $group->first());

        $rows = [];

        foreach ($this->catalogue->all() as $entry) {
            $key = (string) $entry['key'];
            $run = $runs->get($key);

            $rows[] = [
                'strategy' => $key,
                'label' => $entry['label'] ?? $key,
                // Null rather than a row of zeroes: "never run" is not "run and
                // returned nothing", and a comparison that cannot tell them
                // apart is worse than one that admits the gap.
                'run' => $run instanceof Backtest ? $this->present($run) : null,
            ];
        }

        return ApiResponse::success([
            'symbol' => $symbol,
            'strategies' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Backtest $run, bool $withTrades = false): array
    {
        $payload = [
            'run_id' => $run->run_id,
            'symbol' => $run->symbol,
            'strategy' => $run->strategy,
            'source' => $run->source,
            'created_at' => $run->created_at?->toIso8601String(),
            'params' => $run->params_json,
            'stats' => $run->stats_json,
            'notes' => $run->notes,
        ];

        if ($withTrades) {
            $payload['trades'] = $run->trades()
                ->orderBy('entry_date')
                ->get()
                ->map(static fn ($trade): array => [
                    'entry_date' => $trade->entry_date?->format('Y-m-d'),
                    'exit_date' => $trade->exit_date?->format('Y-m-d'),
                    'entry_px' => (float) $trade->entry_px,
                    'exit_px' => $trade->exit_px === null ? null : (float) $trade->exit_px,
                    'units' => (float) $trade->units,
                    'pnl' => $trade->pnl === null ? null : (float) $trade->pnl,
                ])
                ->all();
        }

        return $payload;
    }
}

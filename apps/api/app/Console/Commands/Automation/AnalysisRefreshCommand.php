<?php

namespace App\Console\Commands\Automation;

use App\Models\Price;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\TradingWeekResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Rebuild everything derived from the day's raw data, in dependency order.
 *
 * The scrapes land bars and broker windows. On their own those update no
 * dashboard: features_daily, metrics, broker_accumulation_windows,
 * watchlist_scores and the strategy runs are all computed from them, and until
 * something recomputes them the analysis pages show yesterday under today's
 * date -- which is worse than showing nothing, because it looks current.
 *
 * The order is the dependency graph and is not arbitrary:
 *
 *   features:extract                    reads prices and broker windows
 *   asset:metrics --persist             reads prices, and PBAS from features
 *   strategy:rollup-broker-accumulation reads broker windows
 *   strategy:rank-watchlist             reads features and the rollups
 *   strategy:run                        reads features
 *
 * The job is scheduled behind the two scrapes in the same dispatcher pass, so
 * by the time it starts the bars and the broker windows for the day are in.
 *
 * It also catches up. Rather than recomputing only today, it re-extracts from
 * the newest date features already exist for through the newest date a price
 * bar exists for -- so a night the scrape failed, or an evening where the
 * broker window arrived after the features were first computed, is repaired on
 * the next run instead of leaving a permanent hole. The span is bounded, and
 * every step is an upsert, so running it twice changes nothing.
 *
 * A failing step is recorded and the rest still run. These outputs are
 * independent enough that abandoning the remaining four because the first
 * struggled would leave more of the dashboard stale, not less.
 */
class AnalysisRefreshCommand extends Command
{
    protected $signature = 'automation:analysis-refresh
        {--date= : Recompute for this date only (YYYY-MM-DD)}
        {--from= : Earliest date to re-extract features for (YYYY-MM-DD)}
        {--max-days=10 : Most days of features one run will rebuild}
        {--symbol=* : Restrict the whole refresh to specific tickers}
        {--skip-features : Do not rebuild features_daily}
        {--skip-metrics : Do not recompute asset metrics}
        {--skip-rollup : Do not rebuild broker accumulation windows}
        {--skip-watchlist : Do not rescore the watchlist}
        {--skip-strategies : Do not re-run the saved rule-builder strategies}';

    protected $description = 'Recompute features, metrics, broker rollups, watchlist scores and strategy runs from the latest imported data.';

    public function handle(TradingWeekResolver $calendar, RunMetadata $metadata): int
    {
        $startedAt = microtime(true);

        foreach (['date', 'from'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && trim($value) !== '' && ! Carbon::hasFormat(trim($value), 'Y-m-d')) {
                $this->error(sprintf('--%s must be a YYYY-MM-DD date.', $option));

                return self::INVALID;
            }
        }

        $symbols = $this->resolveSymbols();
        $range = $this->resolveRange($calendar, $symbols);

        $metadata->merge([
            'job' => 'analysis_refresh',
            'timezone' => $calendar->timezone(),
            'market_date' => $calendar->today()->toDateString(),
            'symbol_count' => count($symbols),
        ]);

        if ($range === null) {
            // No price bars at all, so nothing downstream can be computed. Not
            // a failure: a fresh installation looks exactly like this until
            // the first OHLCV sync runs.
            $metadata->merge([
                'skipped' => true,
                'skip_reason' => 'no_price_bars',
                'error_summary' => 'No price bars are stored, so there is nothing to derive. Run the daily OHLCV sync first.',
            ]);

            $this->warn('No price bars are stored; there is nothing to recompute.');

            return self::SUCCESS;
        }

        [$from, $to] = $range;

        $metadata->merge([
            'features_from' => $from->toDateString(),
            'scan_date' => $to->toDateString(),
            'days_rebuilt' => max(1, (int) $from->diffInDays($to) + 1),
        ]);

        $this->info(sprintf(
            'Refreshing analysis for %s → %s%s.',
            $from->toDateString(),
            $to->toDateString(),
            $symbols === [] ? '' : sprintf(' (%d ticker(s))', count($symbols)),
        ));

        $steps = [
            'features' => [
                'skip' => 'skip-features',
                'command' => 'features:extract',
                'parameters' => array_filter([
                    '--from' => $from->toDateString(),
                    '--to' => $to->toDateString(),
                    // features:extract takes one symbol, not a list, so a
                    // multi-symbol restriction becomes one call per symbol.
                    '--symbol' => count($symbols) === 1 ? $symbols[0] : null,
                ]),
                'fanout' => count($symbols) > 1 ? '--symbol' : null,
            ],
            'metrics' => [
                'skip' => 'skip-metrics',
                'command' => 'asset:metrics',
                // Always one of --all or --sym: with neither, asset:metrics
                // prompts, and a scheduled run has nobody to answer it.
                // --as-of pins the cache to the same session everything else
                // in this pass describes. Without it a catch-up run would
                // rebuild features for an old date and then stamp the cache
                // with today's, which is the mismatch the canonical snapshot
                // service exists to prevent.
                'parameters' => array_merge(
                    ['--persist' => true, '--as-of' => $to->toDateString()],
                    $symbols === [] ? ['--all' => true] : ['--sym' => implode(',', $symbols)],
                ),
            ],
            'rollup' => [
                'skip' => 'skip-rollup',
                'command' => 'strategy:rollup-broker-accumulation',
                'parameters' => array_filter([
                    '--date' => $to->toDateString(),
                    '--symbol' => $symbols === [] ? null : $symbols,
                ]),
            ],
            'watchlist' => [
                'skip' => 'skip-watchlist',
                'command' => 'strategy:rank-watchlist',
                'parameters' => array_filter([
                    '--date' => $to->toDateString(),
                    '--symbol' => $symbols === [] ? null : $symbols,
                ]),
            ],
            'strategies' => [
                'skip' => 'skip-strategies',
                'command' => 'strategy:run',
                'parameters' => ['--date' => $to->toDateString()],
            ],
        ];

        $results = [];
        $failed = [];

        foreach ($steps as $name => $step) {
            if ($this->option($step['skip'])) {
                $results[$name] = ['status' => 'skipped'];
                $this->line(sprintf('  %s: skipped (--%s).', $name, $step['skip']));

                continue;
            }

            $results[$name] = $this->runStep(
                $name,
                (string) $step['command'],
                $step['parameters'],
                $step['fanout'] ?? null,
                $symbols,
            );

            if ($results[$name]['status'] !== 'ok') {
                $failed[] = $name;
            }
        }

        $metadata->merge([
            'steps' => $results,
            'failed_steps' => $failed,
            'partial' => $failed !== [],
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ]);

        if ($failed !== []) {
            $metadata->set('error_summary', sprintf(
                '%d of %d analysis step(s) failed: %s. The rest completed.',
                count($failed),
                count($steps),
                implode(', ', $failed),
            ));

            $this->warn(sprintf('%d step(s) failed: %s.', count($failed), implode(', ', $failed)));

            return self::FAILURE;
        }

        $this->info('Every analysis step completed.');

        return self::SUCCESS;
    }

    /**
     * Run one step, capturing its outcome without letting it end the run.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<int, string>  $symbols
     * @return array{status: string, exit_code?: int, message?: string, calls?: int}
     */
    private function runStep(
        string $name,
        string $command,
        array $parameters,
        ?string $fanout,
        array $symbols,
    ): array {
        // One call per symbol for commands that only take a single one. The
        // list is short whenever it is used at all -- an unrestricted run
        // passes no symbols and makes exactly one call.
        $invocations = $fanout === null
            ? [$parameters]
            : array_map(
                static fn (string $symbol): array => $parameters + [$fanout => $symbol],
                $symbols,
            );

        $worstExitCode = self::SUCCESS;

        foreach ($invocations as $invocation) {
            try {
                // A BufferedOutput rather than this command's own: the
                // scheduler stores captured output and a per-asset feature
                // table for four hundred tickers would fill it entirely,
                // pushing out the lines that say what went wrong.
                $exitCode = Artisan::call($command, $invocation, new BufferedOutput);
            } catch (Throwable $exception) {
                $this->error(sprintf('  %s: failed -- %s', $name, $exception->getMessage()));

                return [
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'calls' => count($invocations),
                ];
            }

            if ($exitCode !== self::SUCCESS) {
                $worstExitCode = $exitCode;
            }
        }

        if ($worstExitCode !== self::SUCCESS) {
            $this->warn(sprintf('  %s: exited %d.', $name, $worstExitCode));

            return ['status' => 'failed', 'exit_code' => $worstExitCode, 'calls' => count($invocations)];
        }

        $this->line(sprintf('  %s: ok.', $name));

        return ['status' => 'ok', 'calls' => count($invocations)];
    }

    /**
     * The span of dates to rebuild, or null when there is no data at all.
     *
     * `to` is the newest date a price bar exists for, not today: the analysis
     * can only be as current as the data behind it, and claiming today when
     * the scrape has not landed would date every derived row wrongly.
     *
     * `from` reaches back to the newest date features already cover, which
     * means that date is always recomputed. That is deliberate. The broker
     * summary for a day is imported after the bars, and often after the
     * features for that day were first written, so the last computed day is
     * precisely the one most likely to have been built from incomplete inputs.
     *
     * @param  array<int, string>  $symbols
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveRange(TradingWeekResolver $calendar, array $symbols): ?array
    {
        $timezone = $calendar->timezone();
        $explicit = $this->option('date');

        if (is_string($explicit) && trim($explicit) !== '') {
            $date = Carbon::parse(trim($explicit), $timezone)->startOfDay();

            return [$date, $date];
        }

        $latestBar = Price::query()->max('date');

        if ($latestBar === null || $latestBar === '') {
            return null;
        }

        $to = Carbon::parse((string) $latestBar, $timezone)->startOfDay();

        $maxDays = max(1, (int) $this->option('max-days'));
        $floor = $to->copy()->subDays($maxDays - 1);

        $fromOption = $this->option('from');

        if (is_string($fromOption) && trim($fromOption) !== '') {
            $from = Carbon::parse(trim($fromOption), $timezone)->startOfDay();

            return [$from->greaterThan($to) ? $to : $from, $to];
        }

        $latestFeature = DB::table('features_daily')->max('date');

        $from = $latestFeature === null || $latestFeature === ''
            ? $to->copy()
            : Carbon::parse((string) $latestFeature, $timezone)->startOfDay();

        if ($from->lessThan($floor)) {
            // A long gap is rebuilt a bounded slice at a time rather than in
            // one run that would hold the scheduler's whole budget. Successive
            // nights walk it forward.
            $from = $floor;
        }

        return [$from->greaterThan($to) ? $to : $from, $to];
    }

    /**
     * @return array<int, string>
     */
    private function resolveSymbols(): array
    {
        $normalized = [];

        foreach ((array) $this->option('symbol') as $raw) {
            foreach (explode(',', (string) $raw) as $candidate) {
                $symbol = strtoupper(trim($candidate));

                if ($symbol !== '') {
                    $normalized[$symbol] = $symbol;
                }
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }
}

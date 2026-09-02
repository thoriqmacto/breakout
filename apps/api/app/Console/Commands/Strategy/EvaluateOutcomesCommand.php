<?php

namespace App\Console\Commands\Strategy;

use App\Services\Strategy\SignalOutcomeEvaluator;
use App\Services\Strategy\StrategyProfile;
use App\Services\Strategy\StrategyScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Builds the forward-outcome history the probability engine reads.
 *
 * Run behind the daily analysis refresh over a short trailing window, or by
 * hand over a long one to backfill. Idempotent: a signal already evaluated
 * under this profile version is overwritten with the same answer, because the
 * inputs to that answer are all as-of and do not change.
 *
 * A trailing window is not merely a performance choice. A signal near the end
 * of the data has not had time to resolve, so re-evaluating recent sessions
 * on each run is what converts those unresolved rows into answers as the
 * bars arrive.
 */
class EvaluateOutcomesCommand extends Command
{
    protected $signature = 'strategy:evaluate-outcomes
        {--from= : First signal date (YYYY-MM-DD). Defaults to --lookback sessions back.}
        {--to= : Last signal date (YYYY-MM-DD). Defaults to today.}
        {--lookback=90 : Calendar days back from --to when --from is not given.}
        {--symbol=* : Restrict to one or more tickers.}
        {--score-version=v1 : Which watchlist_scores version supplies the signal spine.}
        {--profile-version= : Override the strategy profile version label.}';

    protected $description = 'Simulate forward outcomes for historical signals and persist them for probability lookups.';

    public function handle(SignalOutcomeEvaluator $evaluator): int
    {
        $to = $this->resolveDate($this->option('to')) ?? Carbon::now()->startOfDay();
        $from = $this->resolveDate($this->option('from'))
            ?? $to->copy()->subDays(max(1, (int) $this->option('lookback')));

        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return self::INVALID;
        }

        $overrides = [];
        $profileVersion = $this->option('profile-version');

        if (is_string($profileVersion) && trim($profileVersion) !== '') {
            $overrides['version'] = trim($profileVersion);
        }

        $profile = StrategyProfile::fromConfig($overrides);
        $symbols = $this->parseSymbols((array) $this->option('symbol'));

        $this->info(sprintf(
            'Evaluating outcomes for %s → %s under profile %s.',
            $from->toDateString(),
            $to->toDateString(),
            $profile->version,
        ));

        $report = $evaluator->evaluate(
            $from,
            $to,
            $profile,
            $symbols,
            (string) ($this->option('score-version') ?: StrategyScoringService::VERSION),
        );

        $this->line(sprintf('  %d signal(s) considered', $report['signals']));
        $this->line(sprintf('  %d had a usable plan and next session', $report['evaluated']));
        $this->line(sprintf('  %d never traded through the trigger', $report['not_triggered']));
        $this->line(sprintf('  %d had no valid plan', $report['no_plan']));
        $this->line(sprintf('  %d were missing data', $report['missing_data']));
        $this->info(sprintf('  %d outcome(s) written, %d fully resolved', $report['persisted'], $report['resolved']));

        if ($report['persisted'] > 0 && $report['resolved'] === 0) {
            $this->warn('No outcome resolved: every simulated trade is still open at the end of the available bars.');
        }

        return self::SUCCESS;
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<int, string>|null
     */
    private function parseSymbols(array $raw): ?array
    {
        $out = [];

        foreach ($raw as $item) {
            foreach (explode(',', (string) $item) as $symbol) {
                $symbol = strtoupper(trim($symbol));

                if ($symbol !== '' && ! in_array($symbol, $out, true)) {
                    $out[] = $symbol;
                }
            }
        }

        return $out === [] ? null : $out;
    }
}

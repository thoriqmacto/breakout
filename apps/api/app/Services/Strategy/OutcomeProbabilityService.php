<?php

namespace App\Services\Strategy;

use App\Models\StrategySignalOutcome;
use Illuminate\Support\Carbon;

/**
 * How often setups like this one reached +5% before their stop.
 *
 * An empirical frequency over stored outcomes, and nothing more. It is not a
 * forecast, it does not know anything about this candidate that the bucket
 * does not capture, and the sample it came from is always reported alongside
 * it -- because "64%" and "64% of 117" are different claims and only the
 * second one is honest.
 *
 * Three rules keep it from overstating what it knows:
 *
 *   Below `minimum_probability_sample` the answer is INSUFFICIENT_SAMPLE, not
 *   a number. A hit rate over eleven trades rendered to one decimal place
 *   invites a decision it cannot support.
 *
 *   Unresolved signals are excluded, never counted as misses. A trade whose
 *   forward window ended before either level was touched has no answer, and
 *   treating it as a loss would bias every statistic downward at the recent
 *   end of the data -- where the sample is thinnest and the reader is looking
 *   hardest.
 *
 *   `as_of` excludes outcomes whose signal date is not strictly before the
 *   date being scored. Without it, scoring a historical date would consult
 *   outcomes that had not happened yet, which is the same look-ahead the rest
 *   of the pipeline is built to avoid -- just laundered through a statistic.
 */
class OutcomeProbabilityService
{
    public const INSUFFICIENT_SAMPLE = 'INSUFFICIENT_SAMPLE';

    public const MATCH_EXACT = 'exact';

    public const MATCH_COARSE = 'coarse';

    /**
     * Statistics for one bucket, or a stated refusal to produce them.
     *
     * @return array<string, mixed>
     */
    public function forBucket(SetupBucket $bucket, StrategyProfile $profile, ?Carbon $asOf = null): array
    {
        $exact = $this->summarise($this->rows($profile->version, 'setup_bucket', $bucket->key(), $asOf));

        if ($exact['sample_size'] >= $profile->minimumProbabilitySample) {
            return $this->present($exact, $bucket, $profile, self::MATCH_EXACT);
        }

        // Too thin. A wider but still comparable population beats no answer,
        // as long as the reader is told the comparison was widened.
        $coarse = $this->summarise($this->rows($profile->version, 'coarse', $bucket->coarseKey(), $asOf));

        if ($coarse['sample_size'] >= $profile->minimumProbabilitySample) {
            return $this->present($coarse, $bucket, $profile, self::MATCH_COARSE, $exact['sample_size']);
        }

        return [
            'status' => self::INSUFFICIENT_SAMPLE,
            'match' => null,
            'bucket' => $bucket->key(),
            'bucket_label' => $bucket->label(),
            'sample_size' => $coarse['sample_size'],
            'exact_sample_size' => $exact['sample_size'],
            'minimum_sample' => $profile->minimumProbabilitySample,
            'probability_hit_5_before_stop' => null,
            'median_days_to_5' => null,
            'median_mae_pct' => null,
            'median_mfe_pct' => null,
            'median_trailing_exit_return_pct' => null,
            'win_rate' => null,
            'expectancy_pct' => null,
            'profit_factor' => null,
            'median_hold_sessions' => null,
            'strategy_version' => $profile->version,
        ];
    }

    /**
     * Statistics for many buckets at once, so a page of candidates costs a
     * handful of queries rather than one per row.
     *
     * @param  array<string, SetupBucket>  $buckets  keyed by whatever the caller wants back
     * @return array<string, array<string, mixed>>
     */
    public function forBuckets(array $buckets, StrategyProfile $profile, ?Carbon $asOf = null): array
    {
        if ($buckets === []) {
            return [];
        }

        $exactKeys = [];
        $coarseKeys = [];

        foreach ($buckets as $bucket) {
            $exactKeys[$bucket->key()] = true;
            $coarseKeys[$bucket->coarseKey()] = true;
        }

        $exactRows = $this->groupedRows($profile->version, array_keys($exactKeys), $asOf, coarse: false);
        $coarseRows = $this->groupedRows($profile->version, array_keys($coarseKeys), $asOf, coarse: true);

        $out = [];

        foreach ($buckets as $reference => $bucket) {
            $exact = $this->summarise($exactRows[$bucket->key()] ?? []);

            if ($exact['sample_size'] >= $profile->minimumProbabilitySample) {
                $out[$reference] = $this->present($exact, $bucket, $profile, self::MATCH_EXACT);

                continue;
            }

            $coarse = $this->summarise($coarseRows[$bucket->coarseKey()] ?? []);

            if ($coarse['sample_size'] >= $profile->minimumProbabilitySample) {
                $out[$reference] = $this->present($coarse, $bucket, $profile, self::MATCH_COARSE, $exact['sample_size']);

                continue;
            }

            $out[$reference] = [
                'status' => self::INSUFFICIENT_SAMPLE,
                'match' => null,
                'bucket' => $bucket->key(),
                'bucket_label' => $bucket->label(),
                'sample_size' => $coarse['sample_size'],
                'exact_sample_size' => $exact['sample_size'],
                'minimum_sample' => $profile->minimumProbabilitySample,
                'probability_hit_5_before_stop' => null,
                'median_days_to_5' => null,
                'median_mae_pct' => null,
                'median_mfe_pct' => null,
                'median_trailing_exit_return_pct' => null,
                'win_rate' => null,
                'expectancy_pct' => null,
                'profit_factor' => null,
                'median_hold_sessions' => null,
                'strategy_version' => $profile->version,
            ];
        }

        return $out;
    }

    /**
     * Resolved outcomes for one bucket key.
     *
     * @return array<int, StrategySignalOutcome>
     */
    private function rows(string $version, string $mode, string $key, ?Carbon $asOf): array
    {
        $query = StrategySignalOutcome::query()
            ->where('strategy_version', $version)
            ->where('resolved', true);

        if ($mode === 'coarse') {
            // The coarse key is the first two segments of the stored key, so
            // a prefix match is exactly the widening intended -- and it needs
            // no second column to drift out of step with the first.
            $query->where('setup_bucket', 'like', $key.'|%');
        } else {
            $query->where('setup_bucket', $key);
        }

        if ($asOf !== null) {
            $query->whereDate('signal_date', '<', $asOf->toDateString());
        }

        return $query->get()->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array<int, StrategySignalOutcome>>
     */
    private function groupedRows(string $version, array $keys, ?Carbon $asOf, bool $coarse): array
    {
        if ($keys === []) {
            return [];
        }

        $query = StrategySignalOutcome::query()
            ->where('strategy_version', $version)
            ->where('resolved', true);

        if ($coarse) {
            $query->where(function ($inner) use ($keys) {
                foreach ($keys as $key) {
                    $inner->orWhere('setup_bucket', 'like', $key.'|%');
                }
            });
        } else {
            $query->whereIn('setup_bucket', $keys);
        }

        if ($asOf !== null) {
            $query->whereDate('signal_date', '<', $asOf->toDateString());
        }

        $out = [];

        foreach ($query->get() as $row) {
            $bucket = (string) $row->setup_bucket;
            $group = $coarse ? $this->coarsePrefix($bucket) : $bucket;
            $out[$group][] = $row;
        }

        return $out;
    }

    private function coarsePrefix(string $bucket): string
    {
        $parts = explode('|', $bucket);

        return implode('|', array_slice($parts, 0, 2));
    }

    /**
     * @param  array<int, StrategySignalOutcome>  $rows
     * @return array<string, mixed>
     */
    private function summarise(array $rows): array
    {
        $sample = count($rows);

        if ($sample === 0) {
            return ['sample_size' => 0];
        }

        $hits = 0;
        $daysToFive = [];
        $maes = [];
        $mfes = [];
        $returns = [];
        $holds = [];
        $wins = 0;
        $graded = 0;
        $grossWins = 0.0;
        $grossLosses = 0.0;

        foreach ($rows as $row) {
            if ($row->reached_5pct_before_stop) {
                $hits++;

                if ($row->days_to_5pct !== null) {
                    $daysToFive[] = (int) $row->days_to_5pct;
                }
            }

            if ($row->mae_5d !== null) {
                $maes[] = (float) $row->mae_5d;
            }

            if ($row->mfe_5d !== null) {
                $mfes[] = (float) $row->mfe_5d;
            }

            $net = $row->net_return_pct;

            if ($net !== null) {
                $net = (float) $net;
                $returns[] = $net;
                $holds[] = (int) $row->hold_sessions;
                $graded++;

                if ($net > 0) {
                    $wins++;
                    $grossWins += $net;
                } else {
                    $grossLosses += abs($net);
                }
            }
        }

        return [
            'sample_size' => $sample,
            'hits' => $hits,
            'probability' => round($hits / $sample, 4),
            'median_days_to_5' => $this->median($daysToFive),
            'median_mae_pct' => $this->median($maes),
            'median_mfe_pct' => $this->median($mfes),
            'median_return_pct' => $this->median($returns),
            'median_hold_sessions' => $this->median($holds),
            'win_rate' => $graded > 0 ? round($wins / $graded, 4) : null,
            'expectancy_pct' => $graded > 0 ? round(array_sum($returns) / $graded, 4) : null,
            'profit_factor' => $grossLosses > 0.0 ? round($grossWins / $grossLosses, 4) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function present(
        array $summary,
        SetupBucket $bucket,
        StrategyProfile $profile,
        string $match,
        ?int $exactSample = null,
    ): array {
        return [
            'status' => 'OK',
            'match' => $match,
            'bucket' => $bucket->key(),
            'bucket_label' => $match === self::MATCH_COARSE ? $bucket->coarseLabel() : $bucket->label(),
            'sample_size' => $summary['sample_size'],
            'exact_sample_size' => $exactSample ?? $summary['sample_size'],
            'minimum_sample' => $profile->minimumProbabilitySample,
            'probability_hit_5_before_stop' => $summary['probability'],
            'median_days_to_5' => $summary['median_days_to_5'],
            'median_mae_pct' => $summary['median_mae_pct'],
            'median_mfe_pct' => $summary['median_mfe_pct'],
            'median_trailing_exit_return_pct' => $summary['median_return_pct'],
            'win_rate' => $summary['win_rate'],
            'expectancy_pct' => $summary['expectancy_pct'],
            'profit_factor' => $summary['profit_factor'],
            'median_hold_sessions' => $summary['median_hold_sessions'],
            'strategy_version' => $profile->version,
        ];
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;

        return round($median, 4);
    }
}

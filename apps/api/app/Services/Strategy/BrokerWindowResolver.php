<?php

namespace App\Services\Strategy;

use App\Models\BrokerSummaryWindow;
use Illuminate\Support\Carbon;

/**
 * Chooses which broker-summary windows may inform a given date.
 *
 * The strategy services used to read broker_summary_facts, one row per broker
 * per trading day. That table now only receives genuinely single-day imports,
 * because a Stockbit summary for from=2026-05-26&to=2026-08-26 is one aggregate
 * over three months and stamping it onto a trade_date was the bug the window
 * model exists to remove. Reading windows instead loses nothing -- the importer
 * writes a window for every file and facts only for the single-day ones, so
 * windows are a superset -- and it lets a ranged import be used as what it is.
 *
 * Two rules are enforced here so the three consumers cannot drift apart:
 *
 *   1. No window ending after the date may inform it. A window covering
 *      2026-05-26..2026-08-26 contains 15 June, but using it to score 15 June
 *      feeds two further months of hindsight into that day. That is lookahead
 *      bias, and it silently flatters a backtest.
 *
 *   2. Windows combined into one rollup must not overlap, or the same flow is
 *      counted twice.
 */
class BrokerWindowResolver
{
    /**
     * How stale an as-of window may be before it is ignored, in days.
     *
     * Without a bound the most recent window is used forever, so a symbol that
     * stopped being imported in March would still present March's flow as the
     * broker picture for September. Null disables the bound.
     */
    public function maxStalenessDays(): ?int
    {
        $configured = config('stockbit.strategy.max_window_staleness_days', 7);

        if ($configured === null) {
            return null;
        }

        return max(0, (int) $configured);
    }

    /**
     * The window that best describes the broker picture as of $date.
     *
     * "As of" means it must have ended on or before $date -- the most recent
     * such window wins, and where two ended on the same day the narrower one
     * does, so a single-day summary still beats a three-month one. For daily
     * data this picks exactly the row the old whereDate('trade_date', $date)
     * query did.
     */
    public function asOf(int $assetId, Carbon $date, ?string $transactionType = null): ?BrokerSummaryWindow
    {
        $query = BrokerSummaryWindow::query()
            ->with('entries')
            ->where('asset_id', $assetId)
            ->whereDate('to_date', '<=', $date->toDateString());

        $staleness = $this->maxStalenessDays();

        if ($staleness !== null) {
            $query->whereDate('to_date', '>=', $date->copy()->subDays($staleness)->toDateString());
        }

        $this->constrainType($query, $transactionType);

        $candidates = $query->get()->all();

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (BrokerSummaryWindow $a, BrokerSummaryWindow $b): int {
            // Latest end first, then the narrower range.
            return [$b->to_date->getTimestamp(), $a->spanDays()]
                <=> [$a->to_date->getTimestamp(), $b->spanDays()];
        });

        return $candidates[0];
    }

    /**
     * asOf() for many assets in one query.
     *
     * The per-asset form is one query each, which is fine for a single symbol
     * and is four hundred round trips when the execution workspace builds a
     * page. Same selection rule, applied in PHP over one result set.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, BrokerSummaryWindow> keyed by asset id
     */
    public function asOfMany(array $assetIds, Carbon $date, ?string $transactionType = null): array
    {
        if ($assetIds === []) {
            return [];
        }

        $query = BrokerSummaryWindow::query()
            ->with(['entries', 'bandarDetectorSummary'])
            ->whereIn('asset_id', $assetIds)
            ->whereDate('to_date', '<=', $date->toDateString());

        $staleness = $this->maxStalenessDays();

        if ($staleness !== null) {
            $query->whereDate('to_date', '>=', $date->copy()->subDays($staleness)->toDateString());
        }

        $this->constrainType($query, $transactionType);

        $best = [];

        foreach ($query->get() as $window) {
            $assetId = (int) $window->asset_id;
            $incumbent = $best[$assetId] ?? null;

            // Latest end first, then the narrower range -- so a single-day
            // summary still beats a three-month one that ended the same day.
            if ($incumbent === null
                || [$window->to_date->getTimestamp(), -$window->spanDays()]
                    > [$incumbent->to_date->getTimestamp(), -$incumbent->spanDays()]
            ) {
                $best[$assetId] = $window;
            }
        }

        return $best;
    }

    /**
     * Non-overlapping windows lying entirely inside [$start, $end].
     *
     * Contained rather than merely overlapping: a window that runs past $end
     * carries flow from outside the range, and there is no honest way to take
     * the part that belongs. So a three-month import contributes nothing to a
     * 20-day rollup -- correct, if thin. Longest windows are placed first, as
     * they carry the most evidence, and anything overlapping one already
     * placed is skipped so no flow is counted twice.
     *
     * With only single-day windows this returns exactly the days in the range,
     * which is what the daily aggregator summed.
     *
     * @return array<int, BrokerSummaryWindow>
     */
    public function tiling(int $assetId, Carbon $start, Carbon $end, ?string $transactionType = null): array
    {
        $query = BrokerSummaryWindow::query()
            ->with('entries')
            ->where('asset_id', $assetId)
            ->whereDate('from_date', '>=', $start->toDateString())
            ->whereDate('to_date', '<=', $end->toDateString());

        $this->constrainType($query, $transactionType);

        $candidates = $query->get()->all();

        usort($candidates, static function (BrokerSummaryWindow $a, BrokerSummaryWindow $b): int {
            return [$b->spanDays(), $b->to_date->getTimestamp()]
                <=> [$a->spanDays(), $a->to_date->getTimestamp()];
        });

        $chosen = [];

        foreach ($candidates as $window) {
            foreach ($chosen as $taken) {
                if ($window->from_date <= $taken->to_date && $taken->from_date <= $window->to_date) {
                    continue 2;
                }
            }

            $chosen[] = $window;
        }

        // Chronological, which is how a caller expects to read a tiling.
        usort($chosen, static fn (BrokerSummaryWindow $a, BrokerSummaryWindow $b): int => $a->from_date <=> $b->from_date);

        return $chosen;
    }

    /**
     * Windows ending exactly on $date, to be recorded at their own length.
     *
     * This is the fixed rollup's counterpart: a 3/5/10/20-day rollup ends on
     * the date it is built for, and so does a native one. It is how a
     * three-month import reaches a scan on its closing date without being cut
     * into days it never described. Ending strictly on the date also means a
     * native row can never carry flow from after it.
     *
     * @return array<int, BrokerSummaryWindow>
     */
    public function endingOn(int $assetId, Carbon $date, ?string $transactionType = null): array
    {
        $query = BrokerSummaryWindow::query()
            ->with('entries')
            ->where('asset_id', $assetId)
            ->whereDate('to_date', '=', $date->toDateString());

        $this->constrainType($query, $transactionType);

        return $query->get()->all();
    }

    /**
     * Days covered by a tiling. The windows do not overlap, so their spans add.
     *
     * @param  array<int, BrokerSummaryWindow>  $windows
     */
    public function coveredDays(array $windows): int
    {
        $days = 0;

        foreach ($windows as $window) {
            $days += $window->spanDays();
        }

        return $days;
    }

    /**
     * A transaction type is a different aggregate, not a different slice of the
     * same one, so mixing types into a rollup would double count. Callers pass
     * the configured default rather than leaving it open.
     */
    private function constrainType(mixed $query, ?string $transactionType): void
    {
        if ($transactionType !== null && $transactionType !== '') {
            $query->where('transaction_type', $transactionType);
        }
    }
}

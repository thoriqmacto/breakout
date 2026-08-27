<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\BrokerAccumulationWindow;
use App\Models\BrokerSummaryWindow;
use Illuminate\Support\Carbon;

/**
 * Builds broker net-flow rollups for one or more assets, ending on a given
 * trading date. The output lives in broker_accumulation_windows and is consumed
 * by StrategyScoringService for the Broker Accumulation Score.
 *
 * Two kinds of row are written:
 *
 *   Fixed (3D/5D/10D/20D, configurable) -- assembled from imported windows that
 *   lie entirely inside the rollup's range, chosen so none overlap. When every
 *   import is a single day this is the old "sum the last N days" behaviour
 *   exactly; the days are simply single-day windows.
 *
 *   Native -- one row per imported window ending on the rollup date, recorded
 *   at that window's own length. A three-month broker summary is a single
 *   aggregate over three months: it cannot feed a 20-day rollup without
 *   claiming flow for days outside it, but it is perfectly good evidence at
 *   window_days = 92. StrategyScoringService already weights by window_days,
 *   so this needs no change to the scoring maths.
 *
 * Idempotent: reruns for the same (asset_id, end_date, window_days) upsert.
 */
class BrokerAccumulationAggregator
{
    public const DEFAULT_WINDOWS = [3, 5, 10, 20];

    /**
     * Broker-type labels, as they arrive in the payload's "type" field.
     *
     * Real Stockbit responses use Indonesian ("Asing", "Lokal", "Pemerintah").
     * This used to test for lowercase "foreign"/"local", which matched nothing,
     * so foreign_net_norm and local_net_norm were always zero on live data.
     * Government brokers are deliberately in neither bucket: the rollup has no
     * column for them, and folding them into "local" would misstate both.
     */
    private const FOREIGN_TYPES = ['asing', 'foreign'];

    private const LOCAL_TYPES = ['lokal', 'local'];

    public function __construct(private readonly BrokerWindowResolver $resolver) {}

    /**
     * @param  array<int, int>  $windows
     * @return array{rows_written: int, assets: int, native_rows: int}
     */
    public function rollup(Carbon $endDate, array $windows = self::DEFAULT_WINDOWS, ?array $symbols = null): array
    {
        $assets = $this->resolveAssets($symbols);
        $transactionType = (string) config('stockbit.defaults.transaction_type');
        $rowsWritten = 0;
        $nativeRows = 0;

        foreach ($assets as $asset) {
            // (end_date, window_days) already written for this asset in this
            // run, so a native row of the same length as a fixed one does not
            // upsert over it a second time. They would hold identical figures
            // -- the tiling for that range is that same window -- but writing
            // twice inflates the count and makes the provenance link depend on
            // loop order.
            $written = [];

            foreach ($windows as $windowDays) {
                $windowDays = (int) $windowDays;
                $startDate = $endDate->copy()->subDays($windowDays - 1);

                $tiling = $this->resolver->tiling($asset->id, $startDate, $endDate, $transactionType);
                $row = $this->metrics($tiling);

                if ($row === null) {
                    continue;
                }

                $this->write(
                    assetId: (int) $asset->id,
                    endDate: $endDate,
                    startDate: $startDate,
                    windowDays: $windowDays,
                    coveredDays: $this->resolver->coveredDays($tiling),
                    sourceWindowId: null,
                    metrics: $row,
                );

                $written[$endDate->toDateString().'|'.$windowDays] = true;
                $rowsWritten++;
            }

            // Native rows. A window whose length happens to equal a requested
            // one collides with that fixed row on the unique key -- which is
            // correct, since the tiling for that range is that same window, so
            // both describe identical flow.
            foreach ($this->resolver->endingOn($asset->id, $endDate, $transactionType) as $window) {
                // A single-day window needs no native row: days are exactly
                // what the fixed rollups tile out of, so one would be a
                // duplicate, and emitting it would add a window_days = 1 input
                // that purely daily setups never had before. The guarantee this
                // keeps is that daily data behaves precisely as it used to.
                if ($window->isSingleDay()) {
                    continue;
                }

                if (isset($written[$window->to_date->toDateString().'|'.$window->spanDays()])) {
                    continue;
                }

                $row = $this->metrics([$window]);

                if ($row === null) {
                    continue;
                }

                $this->write(
                    assetId: (int) $asset->id,
                    endDate: $window->to_date,
                    startDate: $window->from_date,
                    windowDays: $window->spanDays(),
                    coveredDays: $window->spanDays(),
                    sourceWindowId: (int) $window->id,
                    metrics: $row,
                );

                $rowsWritten++;
                $nativeRows++;
            }
        }

        return [
            'rows_written' => $rowsWritten,
            'assets' => count($assets),
            'native_rows' => $nativeRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function write(
        int $assetId,
        Carbon $endDate,
        Carbon $startDate,
        int $windowDays,
        int $coveredDays,
        ?int $sourceWindowId,
        array $metrics,
    ): void {
        BrokerAccumulationWindow::updateOrCreate(
            [
                'asset_id' => $assetId,
                'end_date' => $endDate->toDateString(),
                'window_days' => $windowDays,
            ],
            $metrics + [
                'start_date' => $startDate->toDateString(),
                'covered_days' => $coveredDays,
                'source_window_id' => $sourceWindowId,
            ],
        );
    }

    /**
     * Aggregate a set of non-overlapping windows into one rollup row.
     *
     * Both of Stockbit's broker lists hold *net* positions over the window, so
     * an entry's net_value is already signed as the source gave it -- buy-list
     * values positive, sell-list values negative. They are summed as they
     * stand; recomputing a net as buy minus sell would double the figure.
     *
     * @param  array<int, BrokerSummaryWindow>  $windows
     * @return array<string, mixed>|null
     */
    private function metrics(array $windows): ?array
    {
        if ($windows === []) {
            return null;
        }

        $totalGrossValue = 0.0;
        $totalNetValue = 0.0;
        $totalVolume = 0;
        $netByBroker = [];
        $netByType = ['foreign' => 0.0, 'local' => 0.0];

        foreach ($windows as $window) {
            foreach ($window->entries as $entry) {
                $net = (float) ($entry->net_value ?? 0.0);

                // The denominator is the size of the flow, so magnitudes add
                // regardless of side. Matches what the daily aggregator summed
                // as buy_value + sell_value, those being stored as magnitudes.
                $totalGrossValue += abs($net);
                $totalNetValue += $net;
                $totalVolume += abs((int) ($entry->gross_volume ?? 0));

                $broker = (string) ($entry->broker_code ?? '');

                if ($broker !== '') {
                    $netByBroker[$broker] = ($netByBroker[$broker] ?? 0.0) + $net;
                }

                $type = strtolower(trim((string) ($entry->broker_type ?? '')));

                if (in_array($type, self::FOREIGN_TYPES, true)) {
                    $netByType['foreign'] += $net;
                } elseif (in_array($type, self::LOCAL_TYPES, true)) {
                    $netByType['local'] += $net;
                }
            }
        }

        if ($totalGrossValue <= 0.0) {
            return null;
        }

        $brokerCount = count($netByBroker);
        $turnoverValue = $totalGrossValue;

        arsort($netByBroker);
        $top3Net = array_sum(array_slice($netByBroker, 0, 3, true));
        $top5Net = array_sum(array_slice($netByBroker, 0, 5, true));
        $avgNet = $brokerCount > 0 ? ($totalNetValue / $brokerCount) : 0.0;

        $accdistScore = 0;

        if ($totalNetValue > 0 && $top3Net > 0) {
            $accdistScore = 1;
        } elseif ($totalNetValue < 0 && $top3Net < 0) {
            $accdistScore = -1;
        }

        return [
            'avg_net_norm' => $avgNet / $turnoverValue,
            'top3_net_norm' => $top3Net / $turnoverValue,
            'top5_net_norm' => $top5Net / $turnoverValue,
            'foreign_net_norm' => $netByType['foreign'] / $turnoverValue,
            'local_net_norm' => $netByType['local'] / $turnoverValue,
            'accdist_score' => $accdistScore,
            'broker_count' => $brokerCount,
            'value' => round($turnoverValue, 2),
            'volume' => $totalVolume,
        ];
    }

    /**
     * @param  array<int, string>|null  $symbols
     * @return array<int, Asset>
     */
    private function resolveAssets(?array $symbols): array
    {
        $query = Asset::query()->orderBy('symbol');

        if ($symbols !== null && $symbols !== []) {
            $upper = array_map(static fn ($s): string => strtoupper(trim((string) $s)), $symbols);
            $upper = array_values(array_filter($upper, static fn ($s) => $s !== ''));
            $query->whereIn('symbol', $upper);
        }

        return $query->get(['id', 'symbol'])->all();
    }
}

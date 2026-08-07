<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\BrokerAccumulationWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds multi-day broker net-flow rollups (3D/5D/10D/20D, configurable)
 * for one or more assets, ending on a given trading date. The output
 * lives in broker_accumulation_windows and is consumed by
 * StrategyScoringService for the Broker Accumulation Score.
 *
 * Idempotent: reruns for the same (asset_id, end_date, window_days)
 * upsert the row.
 */
class BrokerAccumulationAggregator
{
    public const DEFAULT_WINDOWS = [3, 5, 10, 20];

    /**
     * @param  array<int, int>  $windows
     * @return array{rows_written: int, assets: int}
     */
    public function rollup(Carbon $endDate, array $windows = self::DEFAULT_WINDOWS, ?array $symbols = null): array
    {
        $assets = $this->resolveAssets($symbols);
        $rowsWritten = 0;

        foreach ($windows as $windowDays) {
            $startDate = $endDate->copy()->subDays($windowDays - 1);

            foreach ($assets as $asset) {
                $row = $this->buildWindowRow($asset->id, $startDate, $endDate, (int) $windowDays);
                if ($row === null) {
                    continue;
                }

                BrokerAccumulationWindow::updateOrCreate(
                    [
                        'asset_id' => (int) $asset->id,
                        'end_date' => $endDate->toDateString(),
                        'window_days' => (int) $windowDays,
                    ],
                    $row
                );
                $rowsWritten++;
            }
        }

        return [
            'rows_written' => $rowsWritten,
            'assets' => count($assets),
        ];
    }

    /**
     * Compute the rollup row for a single (asset, window). Returns null
     * if the window has no broker_summary_facts at all (no data, no row).
     *
     * @return array<string, mixed>|null
     */
    private function buildWindowRow(int $assetId, Carbon $startDate, Carbon $endDate, int $windowDays): ?array
    {
        $facts = DB::table('broker_summary_facts')
            ->where('asset_id', $assetId)
            ->whereDate('trade_date', '>=', $startDate->toDateString())
            ->whereDate('trade_date', '<=', $endDate->toDateString())
            ->select('broker_code', 'broker_type', 'buy_value', 'sell_value', 'net_value')
            ->selectRaw('(buy_volume + sell_volume) as gross_volume')
            ->get();

        if ($facts->isEmpty()) {
            return null;
        }

        $totalGrossValue = 0.0;
        $totalNetValue = 0.0;
        $totalVolume = 0;
        $netByBroker = [];
        $netByType = [
            'foreign' => 0.0,
            'local' => 0.0,
        ];

        foreach ($facts as $fact) {
            $buy = (float) $fact->buy_value;
            $sell = (float) $fact->sell_value;
            $net = (float) $fact->net_value;

            $totalGrossValue += $buy + $sell;
            $totalNetValue += $net;
            $totalVolume += (int) ($fact->gross_volume ?? 0);

            $broker = (string) ($fact->broker_code ?? '');
            if ($broker !== '') {
                $netByBroker[$broker] = ($netByBroker[$broker] ?? 0.0) + $net;
            }

            $type = strtolower((string) ($fact->broker_type ?? ''));
            if ($type === 'foreign' || $type === 'local') {
                $netByType[$type] += $net;
            }
        }

        if ($totalGrossValue <= 0.0) {
            return null;
        }

        $brokerCount = count($netByBroker);
        $turnoverValue = $totalGrossValue;

        // Top-N net-buyers by absolute net contribution, normalized by turnover.
        arsort($netByBroker);
        $topBrokers = array_slice($netByBroker, 0, 5, true);
        $top3Net = array_sum(array_slice($netByBroker, 0, 3, true));
        $top5Net = array_sum($topBrokers);

        // Average per-broker net normalized by turnover.
        $avgNet = $brokerCount > 0 ? ($totalNetValue / $brokerCount) : 0.0;

        $accdistScore = 0;
        if ($totalNetValue > 0 && $top3Net > 0) {
            $accdistScore = 1;
        } elseif ($totalNetValue < 0 && $top3Net < 0) {
            $accdistScore = -1;
        }

        return [
            'avg_net_norm' => $turnoverValue > 0 ? $avgNet / $turnoverValue : 0.0,
            'top3_net_norm' => $turnoverValue > 0 ? $top3Net / $turnoverValue : 0.0,
            'top5_net_norm' => $turnoverValue > 0 ? $top5Net / $turnoverValue : 0.0,
            'foreign_net_norm' => $turnoverValue > 0 ? $netByType['foreign'] / $turnoverValue : 0.0,
            'local_net_norm' => $turnoverValue > 0 ? $netByType['local'] / $turnoverValue : 0.0,
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

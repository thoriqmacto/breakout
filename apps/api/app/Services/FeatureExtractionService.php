<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\Price;
use App\Services\Strategy\BrokerWindowResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FeatureExtractionService
{
    private const DEFAULT_LOOKBACK = 100;

    private const MIN_TICK = 0.0001;

    private const PBAS_BROKER_LOOKBACK_DAYS = 30;

    private const PBAS_BROKER_WEIGHT_CURRENT = 0.6;

    private const PBAS_BROKER_WEIGHT_HISTORY = 0.4;

    private const PBAS_BROKER_KEYS = [
        'accdist_score',
        'avg_net_norm',
        'avg5_net_norm',
        'top1_net_norm',
        'top1_sell_share',
        'seller_hhi',
    ];

    public function __construct(private readonly BrokerWindowResolver $brokerWindows) {}

    /**
     * @return array<string, float|int>
     */
    public function extractOhlcvFeatures(Asset $asset, Carbon $date, ?Price $bar = null): array
    {
        $history = $this->loadOhlcvHistory($asset, $date, self::DEFAULT_LOOKBACK);
        if ($bar !== null && (! $history->last() || $history->last()->date->toDateString() !== $date->toDateString())) {
            $history->push($bar);
        }

        if ($history->isEmpty()) {
            return $this->defaultOhlcvFeatures();
        }

        $current = $bar ?? $history->last();
        $closeT = (float) ($current->close ?? 0.0);
        $openT = (float) ($current->open ?? 0.0);
        $highT = (float) ($current->high ?? 0.0);
        $lowT = (float) ($current->low ?? 0.0);
        $volumeT = (float) ($current->volume ?? 0.0);

        $closes = $history->pluck('close')->map(fn ($value) => (float) ($value ?? 0.0))->all();
        $highs = $history->pluck('high')->map(fn ($value) => (float) ($value ?? 0.0))->all();
        $lows = $history->pluck('low')->map(fn ($value) => (float) ($value ?? 0.0))->all();
        $volumes = $history->pluck('volume')->map(fn ($value) => (float) ($value ?? 0.0))->all();

        $closeTm1 = $closes[count($closes) - 2] ?? null;
        $ret1 = $closeTm1 && $closeTm1 > 0 ? ($closeT / $closeTm1 - 1) : 0.0;

        $range = max($highT - $lowT, max($asset->tick_size ?? 0.0, self::MIN_TICK));
        $body = abs($closeT - $openT);
        $closePos = $range > 0 ? ($closeT - $lowT) / $range : 0.0;
        $bodyToRange = $range > 0 ? $body / $range : 0.0;

        $volSma20 = $this->sma($volumes, 20);
        $volRatio20 = $volSma20 && $volSma20 > 0 ? ($volumeT / $volSma20) : 0.0;

        $atr14 = $this->atr($highs, $lows, $closes, 14);
        $atrPct = $closeT > 0 && $atr14 !== null ? ($atr14 / $closeT) : 0.0;

        $ma20 = $this->sma($closes, 20);
        $closeVsMa20 = $ma20 && $ma20 > 0 ? ($closeT / $ma20 - 1) : 0.0;

        $ma20Series = $this->smaSeries($closes, 20);
        $ma20Slope = $this->slope($ma20Series, 5);

        $highest20Prev = count($highs) >= 21
            ? max(array_slice($highs, -21, 20))
            : null;
        $breakout20 = $highest20Prev !== null && $closeT > $highest20Prev ? 1 : 0;

        $atrPctSeries = $this->atrPctSeries($highs, $lows, $closes, 14);
        $atrPctSma10 = $this->sma($atrPctSeries, 10);
        $atrPctMedian = $this->median($atrPctSeries) ?? 0.0;
        $compression = $atrPctSma10 !== null && $atrPct < $atrPctSma10 ? 1 : 0;

        return [
            'ret_1' => $ret1,
            'range_pct' => $closeT > 0 ? ($range / $closeT) : 0.0,
            'close_pos' => $closePos,
            'body_to_range' => $bodyToRange,
            'vol_ratio_20' => $volRatio20,
            'atr_pct' => $atrPct,
            'atr_pct_median' => $atrPctMedian,
            'close_vs_ma20' => $closeVsMa20,
            'ma20_slope' => $ma20Slope,
            'breakout20' => $breakout20,
            'compression' => $compression,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function extractBrokerFeatures(Asset $asset, Carbon $date, ?string $transactionType = null): array
    {
        $features = $this->defaultBrokerFeatures();
        $transactionType ??= (string) config('stockbit.defaults.transaction_type');

        // One window answers both halves of this method. They used to disagree:
        // the detector was resolved by range while the broker rows were an
        // exact trade_date match, so a three-month turnover could end up as the
        // denominator for a single day's numerators.
        //
        // asOf() also declines a window that ends after $date. The previous
        // range query accepted one covering $date, which fed trading from after
        // it into the features for that day.
        $window = $this->brokerWindows->asOf($asset->id, $date, $transactionType);

        $bandarDetector = $window?->bandarDetectorSummary;

        if ($bandarDetector === null) {
            // Detector rows imported before windows existed carry no link back
            // to one. Same as-of and staleness rules, so the fallback cannot be
            // a way in for data the window path would have refused.
            $staleness = $this->brokerWindows->maxStalenessDays();

            $bandarDetector = BandarDetectorSummary::query()
                ->where('asset_id', $asset->id)
                ->when($transactionType !== '', fn ($query) => $query->where('transaction_type', $transactionType))
                ->whereDate('to_date', '<=', $date->toDateString())
                ->when(
                    $staleness !== null,
                    fn ($query) => $query->whereDate(
                        'to_date',
                        '>=',
                        $date->copy()->subDays($staleness)->toDateString(),
                    ),
                )
                ->orderByDesc('to_date')
                ->orderByDesc('from_date')
                ->first();
        }

        $turnoverValue = $bandarDetector ? $this->toNumber($bandarDetector->value) : 0.0;
        $turnoverVolume = $bandarDetector ? (int) $this->toNumber($bandarDetector->volume) : 0;

        $features['turnover_value'] = $turnoverValue;
        $features['turnover_volume'] = $turnoverVolume;
        $features['accdist_score'] = $bandarDetector
            ? $this->mapAccdistToScore((string) $bandarDetector->broker_accdist)
            : 0;

        $denom = $turnoverValue > 0 ? $turnoverValue : null;
        $metrics = $bandarDetector?->metrics_json ?? [];

        $avgAmt = $this->extractMetricAmount($metrics, 'avg');
        $avg5Amt = $this->extractMetricAmount($metrics, 'avg5');

        $features['avg_net_norm'] = $denom ? ($avgAmt / $denom) : 0.0;
        $features['avg5_net_norm'] = $denom ? ($avg5Amt / $denom) : 0.0;

        $features['top1_net_norm'] = $denom ? ($this->extractMetricAmount($metrics, 'top1') / $denom) : 0.0;
        $features['top3_net_norm'] = $denom ? ($this->extractMetricAmount($metrics, 'top3') / $denom) : 0.0;
        $features['top5_net_norm'] = $denom ? ($this->extractMetricAmount($metrics, 'top5') / $denom) : 0.0;
        $features['top10_net_norm'] = $denom ? ($this->extractMetricAmount($metrics, 'top10') / $denom) : 0.0;

        // Entries of the same window the detector came from. Both of Stockbit's
        // lists hold net positions, already signed as the source gave them, so
        // the sign still says which side a broker is on.
        $entries = $window === null ? collect() : $window->entries;

        $buys = [];
        $sells = [];
        $brokerCodes = [];

        foreach ($entries as $entry) {
            $netValue = (float) ($entry->net_value ?? 0.0);
            $brokerCodes[$entry->broker_code] = true;

            $row = [
                'net_value' => $netValue,
                'gross_value' => abs((float) ($entry->gross_value ?? 0.0)),
                // broker_type reaches the entry from the payload's "type" key.
                // toFacts() read netbs_broker_type instead, which live payloads
                // do not carry -- so foreign_net_norm, local_net_norm and
                // gov_net_norm below were always zero on real data.
                'broker_type' => $entry->broker_type,
            ];

            if ($netValue > 0) {
                $buys[] = $row;
            } elseif ($netValue < 0) {
                $sells[] = $row;
            }
        }

        $features['buyer_count'] = count($buys);
        $features['seller_count'] = count($sells);
        $features['active_broker_count'] = count($brokerCodes);

        if ($buys === [] && $sells === []) {
            return $features;
        }

        $features['has_broker'] = 1;

        $buyerShares = [];
        foreach ($buys as $row) {
            $share = $denom ? ($row['net_value'] / $denom) : 0.0;
            if ($share > 0) {
                $buyerShares[] = $share;
            }
        }

        $sellerShares = [];
        foreach ($sells as $row) {
            $share = $denom ? (abs($row['net_value']) / $denom) : 0.0;
            if ($share > 0) {
                $sellerShares[] = $share;
            }
        }

        $features['buyer_hhi'] = $this->sumSquares($buyerShares);
        $features['seller_hhi'] = $this->sumSquares($sellerShares);

        $sortedSells = $sells;
        usort($sortedSells, static fn (array $a, array $b): int => abs($b['net_value']) <=> abs($a['net_value']));

        $features['top1_sell_share'] = $denom && isset($sortedSells[0])
            ? abs($sortedSells[0]['net_value']) / $denom
            : 0.0;
        $features['top3_sell_share'] = $this->topSellShare($sortedSells, 3, $denom);
        $features['top5_sell_share'] = $this->topSellShare($sortedSells, 5, $denom);

        $grossTotal = 0.0;

        foreach ($entries as $entry) {
            // Magnitudes: gross turnover has no side, and a sell entry's values
            // arrive negative.
            $grossTotal += $this->preferGrossValue(
                abs((float) ($entry->gross_value ?? 0.0)),
                abs((float) ($entry->net_value ?? 0.0)),
            );
        }
        $features['net_to_gross_ratio'] = $grossTotal > 0 ? (abs($avgAmt) / $grossTotal) : 0.0;

        $typeNet = [
            'Asing' => 0.0,
            'Lokal' => 0.0,
            'Pemerintah' => 0.0,
        ];

        foreach ($entries as $entry) {
            $type = (string) ($entry->broker_type ?? '');
            if (! array_key_exists($type, $typeNet)) {
                continue;
            }
            $typeNet[$type] += (float) ($entry->net_value ?? 0.0);
        }

        $features['foreign_net_norm'] = $denom ? ($typeNet['Asing'] / $denom) : 0.0;
        $features['local_net_norm'] = $denom ? ($typeNet['Lokal'] / $denom) : 0.0;
        $features['gov_net_norm'] = $denom ? ($typeNet['Pemerintah'] / $denom) : 0.0;

        return $features;
    }

    /**
     * @return array<string, bool>
     */
    public function extractCrossFeatures(array $brokerFeatures, array $ohlcvFeatures): array
    {
        $absorptionFlag =
            ($brokerFeatures['accdist_score'] ?? 0) === -1
            && ($ohlcvFeatures['close_pos'] ?? 0) > 0.45
            && ($ohlcvFeatures['body_to_range'] ?? 0) < 0.5
            && ($ohlcvFeatures['vol_ratio_20'] ?? 0) >= 1.0;

        $distBreakdown =
            (($brokerFeatures['accdist_score'] ?? 0) === -1 || ($brokerFeatures['avg_net_norm'] ?? 0) < 0)
            && ($ohlcvFeatures['close_pos'] ?? 0) < 0.30
            && ($ohlcvFeatures['vol_ratio_20'] ?? 0) > 1.2;

        $stealthAcc =
            (($brokerFeatures['accdist_score'] ?? 0) === 1 || ($brokerFeatures['avg5_net_norm'] ?? 0) > 0)
            && ($ohlcvFeatures['vol_ratio_20'] ?? 0) < 1.0
            && ($ohlcvFeatures['compression'] ?? 0) === 1
            && ($ohlcvFeatures['close_pos'] ?? 0) > 0.55;

        $bandarDistHard =
            ($brokerFeatures['accdist_score'] ?? 0) === -1
            && (
                ($brokerFeatures['top1_net_norm'] ?? 0) <= -0.20
                || ($brokerFeatures['top1_sell_share'] ?? 0) >= 0.30
            )
            && ($brokerFeatures['seller_hhi'] ?? 0) >= 0.20;

        $validLongSetup = ! $distBreakdown && ! $bandarDistHard;

        return [
            'absorption_flag' => $absorptionFlag,
            'dist_breakdown' => $distBreakdown,
            'stealth_acc' => $stealthAcc,
            'bandar_dist_hard' => $bandarDistHard,
            'valid_long_setup' => $validLongSetup,
        ];
    }

    public function makeLabel(Asset $asset, Carbon $date, int $days = 5, float $target = 0.035): ?int
    {
        $entry = Price::query()
            ->where('asset_id', $asset->id)
            ->whereDate('date', $date->toDateString())
            ->value('close');

        if ($entry === null) {
            return null;
        }

        // The next $days *sessions*, not the next $days calendar days. The
        // calendar form reached Wednesday from a Friday and Saturday from a
        // Monday, so it almost never found five bars and the label came back
        // null for most of the week -- a weekend distortion masquerading as
        // missing data. A price bar exists only for a session, so taking the
        // next N bars is the trading-day horizon.
        $futureBars = Price::query()
            ->where('asset_id', $asset->id)
            ->whereDate('date', '>', $date->toDateString())
            ->orderBy('date')
            ->limit($days)
            ->get(['high']);

        if ($futureBars->count() < $days) {
            return null;
        }

        $futureMaxHigh = $futureBars->max('high');
        if ($futureMaxHigh === null || $entry <= 0) {
            return null;
        }

        return ($futureMaxHigh / $entry - 1) >= $target ? 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDailyFeatures(Asset $asset, Carbon $date, ?string $transactionType = null): array
    {
        $bar = Price::query()
            ->where('asset_id', $asset->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        $ohlcvFeatures = $this->extractOhlcvFeatures($asset, $date, $bar);
        $atrPctMedian = (float) ($ohlcvFeatures['atr_pct_median'] ?? 0.0);
        unset($ohlcvFeatures['atr_pct_median']);
        $brokerFeatures = $this->extractBrokerFeatures($asset, $date, $transactionType);
        $brokerHistory = $this->loadBrokerFeatureHistory($asset, $date, self::PBAS_BROKER_LOOKBACK_DAYS);
        $brokerFeaturesForPbas = $this->blendBrokerFeatures($brokerFeatures, $brokerHistory);
        $crossFeatures = $this->extractCrossFeatures($brokerFeaturesForPbas, $ohlcvFeatures);
        $label = $this->makeLabel($asset, $date);
        $pbas = $this->computePreBreakoutAccumulationScore(
            $ohlcvFeatures,
            $brokerFeaturesForPbas,
            $crossFeatures,
            $atrPctMedian
        );

        return array_merge([
            'symbol' => $asset->symbol,
            'date' => $date->toDateString(),
        ], $ohlcvFeatures, $brokerFeatures, $crossFeatures, [
            'pbas' => $pbas,
            'y_hit_5d' => $label,
            'dd_5d' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertDailyFeatures(Asset $asset, Carbon $date, ?string $transactionType = null): array
    {
        $payload = $this->buildDailyFeatures($asset, $date, $transactionType);

        DB::table('features_daily')->upsert(
            [$payload],
            ['symbol', 'date'],
            array_keys($payload)
        );

        return $payload;
    }

    private function loadOhlcvHistory(Asset $asset, Carbon $end, int $lookback)
    {
        $bars = Price::query()
            ->where('asset_id', $asset->id)
            ->whereDate('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->limit($lookback)
            ->get();

        return $bars->sortBy('date')->values();
    }

    /**
     * @return array<string, float>
     */
    private function loadBrokerFeatureHistory(Asset $asset, Carbon $date, int $lookback): array
    {
        $rows = DB::table('features_daily')
            ->where('symbol', $asset->symbol)
            ->whereDate('date', '<', $date->toDateString())
            ->orderByDesc('date')
            ->limit($lookback)
            ->get(self::PBAS_BROKER_KEYS);

        if ($rows->isEmpty()) {
            return [];
        }

        $sums = array_fill_keys(self::PBAS_BROKER_KEYS, 0.0);
        $counts = array_fill_keys(self::PBAS_BROKER_KEYS, 0);

        foreach ($rows as $row) {
            foreach (self::PBAS_BROKER_KEYS as $key) {
                $value = $row->$key ?? null;
                if ($value === null) {
                    continue;
                }
                $sums[$key] += (float) $value;
                $counts[$key]++;
            }
        }

        $averages = [];
        foreach (self::PBAS_BROKER_KEYS as $key) {
            if (($counts[$key] ?? 0) > 0) {
                $averages[$key] = $sums[$key] / $counts[$key];
            }
        }

        return $averages;
    }

    /**
     * @param  array<string, float|int>  $brokerFeatures
     * @param  array<string, float>  $historyAverages
     * @return array<string, float|int>
     */
    private function blendBrokerFeatures(array $brokerFeatures, array $historyAverages): array
    {
        if ($historyAverages === []) {
            return $brokerFeatures;
        }

        $weightCurrent = self::PBAS_BROKER_WEIGHT_CURRENT;
        $weightHistory = self::PBAS_BROKER_WEIGHT_HISTORY;
        $blended = $brokerFeatures;

        foreach ($historyAverages as $key => $avg) {
            if (! array_key_exists($key, $brokerFeatures)) {
                continue;
            }
            $current = (float) $brokerFeatures[$key];
            $blended[$key] = $current * $weightCurrent + $avg * $weightHistory;
        }

        if (array_key_exists('accdist_score', $historyAverages)) {
            $current = (float) ($brokerFeatures['accdist_score'] ?? 0);
            $score = $current * $weightCurrent + $historyAverages['accdist_score'] * $weightHistory;
            $blended['accdist_score'] = $score > 0.25 ? 1 : ($score < -0.25 ? -1 : 0);
        }

        return $blended;
    }

    /**
     * @return array<string, float|int>
     */
    private function defaultOhlcvFeatures(): array
    {
        return [
            'ret_1' => 0.0,
            'range_pct' => 0.0,
            'close_pos' => 0.0,
            'body_to_range' => 0.0,
            'vol_ratio_20' => 0.0,
            'atr_pct' => 0.0,
            'atr_pct_median' => 0.0,
            'close_vs_ma20' => 0.0,
            'ma20_slope' => 0.0,
            'breakout20' => 0,
            'compression' => 0,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function defaultBrokerFeatures(): array
    {
        return [
            'has_broker' => 0,
            'turnover_value' => 0.0,
            'turnover_volume' => 0,
            'accdist_score' => 0,
            'avg_net_norm' => 0.0,
            'avg5_net_norm' => 0.0,
            'top1_net_norm' => 0.0,
            'top3_net_norm' => 0.0,
            'top5_net_norm' => 0.0,
            'top10_net_norm' => 0.0,
            'buyer_count' => 0,
            'seller_count' => 0,
            'active_broker_count' => 0,
            'buyer_hhi' => 0.0,
            'seller_hhi' => 0.0,
            'top1_sell_share' => 0.0,
            'top3_sell_share' => 0.0,
            'top5_sell_share' => 0.0,
            'net_to_gross_ratio' => 0.0,
            'foreign_net_norm' => 0.0,
            'local_net_norm' => 0.0,
            'gov_net_norm' => 0.0,
        ];
    }

    private function sma(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        $slice = array_slice($values, -$period);

        return array_sum($slice) / $period;
    }

    /**
     * @return array<int, float>
     */
    private function smaSeries(array $values, int $period): array
    {
        $series = [];
        $count = count($values);
        if ($count < $period) {
            return $series;
        }

        for ($i = $period; $i <= $count; $i++) {
            $window = array_slice($values, $i - $period, $period);
            $series[] = array_sum($window) / $period;
        }

        return $series;
    }

    private function atr(array $highs, array $lows, array $closes, int $period): ?float
    {
        $count = count($closes);
        if ($count <= $period) {
            return null;
        }

        $tr = [];
        for ($i = 1; $i < $count; $i++) {
            $high = $highs[$i] ?? 0.0;
            $low = $lows[$i] ?? 0.0;
            $prevClose = $closes[$i - 1] ?? 0.0;
            $tr[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        if (count($tr) < $period) {
            return null;
        }

        $slice = array_slice($tr, -$period);

        return array_sum($slice) / $period;
    }

    /**
     * @return array<int, float>
     */
    private function atrPctSeries(array $highs, array $lows, array $closes, int $period): array
    {
        $count = count($closes);
        if ($count <= $period) {
            return [];
        }

        $tr = [];
        for ($i = 1; $i < $count; $i++) {
            $high = $highs[$i] ?? 0.0;
            $low = $lows[$i] ?? 0.0;
            $prevClose = $closes[$i - 1] ?? 0.0;
            $tr[$i] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        $atrPctSeries = [];
        for ($i = $period; $i < $count; $i++) {
            $slice = array_slice($tr, $i - $period + 1, $period, true);
            $atr = array_sum($slice) / $period;
            $close = $closes[$i] ?? 0.0;
            $atrPctSeries[] = $close > 0 ? ($atr / $close) : 0.0;
        }

        return $atrPctSeries;
    }

    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value)));
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
        }

        return (float) $values[$mid];
    }

    /**
     * @param  array<string, float|int>  $ohlcvFeatures
     * @param  array<string, float|int>  $brokerFeatures
     * @param  array<string, bool>  $crossFeatures
     */
    private function computePreBreakoutAccumulationScore(
        array $ohlcvFeatures,
        array $brokerFeatures,
        array $crossFeatures,
        float $atrPctMedian
    ): int {
        $score = 0;
        $penalty = 0;

        $stealthAcc = (int) ($crossFeatures['stealth_acc'] ?? false);
        $compression = (int) ($ohlcvFeatures['compression'] ?? 0);
        $volRatio20 = (float) ($ohlcvFeatures['vol_ratio_20'] ?? 0.0);
        $accdistScore = (int) ($brokerFeatures['accdist_score'] ?? 0);

        if ($stealthAcc === 1) {
            $score += 30;
        } elseif ($compression === 1 && $volRatio20 < 1.0) {
            $score += 20;
        } elseif ($accdistScore === 1) {
            $score += 10;
        }

        $bandarDistHard = (int) ($crossFeatures['bandar_dist_hard'] ?? false);
        $distBreakdown = (int) ($crossFeatures['dist_breakdown'] ?? false);

        if ($bandarDistHard === 1) {
            $penalty += 40;
        } elseif ($distBreakdown === 1) {
            $penalty += 25;
        } elseif ($accdistScore === -1) {
            $penalty += 15;
        }

        $breakout20 = (int) ($ohlcvFeatures['breakout20'] ?? 0);
        $closeVsMa20 = (float) ($ohlcvFeatures['close_vs_ma20'] ?? 0.0);

        if ($breakout20 === 0) {
            if ($closeVsMa20 >= 0.005 && $closeVsMa20 <= 0.03) {
                $score += 20;
            } elseif ($closeVsMa20 > 0.03 && $closeVsMa20 <= 0.05) {
                $score += 10;
            }
        }

        if ($compression === 1) {
            $score += 10;
            $atrPct = (float) ($ohlcvFeatures['atr_pct'] ?? 0.0);
            if ($atrPctMedian > 0.0 && $atrPct < $atrPctMedian) {
                $score += 5;
            }
        }

        if ($volRatio20 >= 0.6 && $volRatio20 <= 1.0) {
            $score += 15;
        } elseif ($volRatio20 > 1.0 && $volRatio20 <= 1.2) {
            $score += 8;
        }

        $ma20Slope = (float) ($ohlcvFeatures['ma20_slope'] ?? 0.0);
        if ($ma20Slope > 0 && $closeVsMa20 > 0) {
            $score += 10;
        } elseif ($ma20Slope > 0) {
            $score += 5;
        }

        $closePos = (float) ($ohlcvFeatures['close_pos'] ?? 0.0);
        if ($closePos >= 0.70) {
            $score += 10;
        } elseif ($closePos >= 0.55) {
            $score += 6;
        }

        if ($breakout20 === 1) {
            $penalty += 20;
        }
        if ($closeVsMa20 > 0.06) {
            $penalty += 10;
        }

        $pbas = $score - $penalty;
        if ($pbas < 0) {
            return 0;
        }
        if ($pbas > 100) {
            return 100;
        }

        return $pbas;
    }

    private function slope(array $series, int $lastN): float
    {
        if (count($series) < $lastN || $lastN < 2) {
            return 0.0;
        }

        $slice = array_slice($series, -$lastN);
        $first = $slice[0];
        $last = $slice[array_key_last($slice)];

        return ($last - $first) / ($lastN - 1);
    }

    private function mapAccdistToScore(string $value): int
    {
        $lower = strtolower(trim($value));
        if ($lower !== '' && str_contains($lower, 'acc')) {
            return 1;
        }

        if ($lower !== '' && str_contains($lower, 'dist')) {
            return -1;
        }

        return 0;
    }

    private function toNumber(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim(str_replace([',', ' '], '', $value));
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return 0.0;
            }

            return (float) $trimmed;
        }

        return 0.0;
    }

    private function extractMetricAmount(array $metrics, string $key): float
    {
        $entry = $metrics[$key] ?? null;
        if (! is_array($entry)) {
            return $this->toNumber($entry);
        }

        return $this->toNumber($entry['amount'] ?? $entry['value'] ?? null);
    }

    private function sumSquares(array $values): float
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += $value * $value;
        }

        return $sum;
    }

    private function topSellShare(array $sells, int $limit, ?float $denom): float
    {
        if ($denom === null || $denom <= 0 || $limit <= 0) {
            return 0.0;
        }

        $slice = array_slice($sells, 0, $limit);
        $total = 0.0;
        foreach ($slice as $row) {
            $total += abs($row['net_value']);
        }

        return $total / $denom;
    }

    private function preferGrossValue(?float $grossValue, ?float $netValue): float
    {
        $grossValue = $this->toNumber($grossValue);
        if ($grossValue > 0) {
            return $grossValue;
        }

        return $this->toNumber($netValue);
    }
}

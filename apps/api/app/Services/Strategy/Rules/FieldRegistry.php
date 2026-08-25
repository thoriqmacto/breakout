<?php

namespace App\Services\Strategy\Rules;

/**
 * The vocabulary a strategy rule may reference.
 *
 * Rules arrive from users, so field names are never interpolated into SQL or
 * resolved dynamically off a model -- a condition naming anything outside this
 * registry is rejected at validation time. Keys are namespaced by source table
 * because `pbas` exists in both features_daily and metrics.
 */
class FieldRegistry
{
    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    /**
     * Daily per-symbol features, the primary evaluation row.
     *
     * @var array<string, array{label: string, type: string, group: string}>
     */
    private const FEATURES = [
        'ret_1' => ['label' => 'Return (1 day)', 'type' => self::TYPE_NUMBER, 'group' => 'Price'],
        'range_pct' => ['label' => 'Range %', 'type' => self::TYPE_NUMBER, 'group' => 'Price'],
        'close_pos' => ['label' => 'Close position in range', 'type' => self::TYPE_NUMBER, 'group' => 'Price'],
        'body_to_range' => ['label' => 'Body to range', 'type' => self::TYPE_NUMBER, 'group' => 'Price'],
        'atr_pct' => ['label' => 'ATR %', 'type' => self::TYPE_NUMBER, 'group' => 'Price'],
        'close_vs_ma20' => ['label' => 'Close vs MA20', 'type' => self::TYPE_NUMBER, 'group' => 'Trend'],
        'ma20_slope' => ['label' => 'MA20 slope', 'type' => self::TYPE_NUMBER, 'group' => 'Trend'],
        'breakout20' => ['label' => 'Breakout (20d)', 'type' => self::TYPE_BOOLEAN, 'group' => 'Trend'],
        'compression' => ['label' => 'Compression', 'type' => self::TYPE_NUMBER, 'group' => 'Trend'],
        'vol_ratio_20' => ['label' => 'Volume vs 20d average', 'type' => self::TYPE_NUMBER, 'group' => 'Volume'],
        'turnover_value' => ['label' => 'Turnover value', 'type' => self::TYPE_NUMBER, 'group' => 'Volume'],
        'turnover_volume' => ['label' => 'Turnover volume', 'type' => self::TYPE_NUMBER, 'group' => 'Volume'],
        'has_broker' => ['label' => 'Has broker data', 'type' => self::TYPE_BOOLEAN, 'group' => 'Broker flow'],
        'accdist_score' => ['label' => 'Accumulation/distribution score', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'avg_net_norm' => ['label' => 'Average net (normalised)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'avg5_net_norm' => ['label' => 'Average net 5d (normalised)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'top1_net_norm' => ['label' => 'Top 1 broker net', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'top3_net_norm' => ['label' => 'Top 3 broker net', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'top5_net_norm' => ['label' => 'Top 5 broker net', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'top10_net_norm' => ['label' => 'Top 10 broker net', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'buyer_count' => ['label' => 'Buyer count', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'seller_count' => ['label' => 'Seller count', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'active_broker_count' => ['label' => 'Active broker count', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'buyer_hhi' => ['label' => 'Buyer concentration (HHI)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'seller_hhi' => ['label' => 'Seller concentration (HHI)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'net_to_gross_ratio' => ['label' => 'Net to gross ratio', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'foreign_net_norm' => ['label' => 'Foreign net (normalised)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'local_net_norm' => ['label' => 'Local net (normalised)', 'type' => self::TYPE_NUMBER, 'group' => 'Broker flow'],
        'absorption_flag' => ['label' => 'Absorption flag', 'type' => self::TYPE_BOOLEAN, 'group' => 'Signals'],
        'stealth_acc' => ['label' => 'Stealth accumulation', 'type' => self::TYPE_BOOLEAN, 'group' => 'Signals'],
        'dist_breakdown' => ['label' => 'Distribution breakdown', 'type' => self::TYPE_BOOLEAN, 'group' => 'Signals'],
        'bandar_dist_hard' => ['label' => 'Hard bandar distribution', 'type' => self::TYPE_BOOLEAN, 'group' => 'Signals'],
        'valid_long_setup' => ['label' => 'Valid long setup', 'type' => self::TYPE_BOOLEAN, 'group' => 'Signals'],
        'pbas' => ['label' => 'PBAS', 'type' => self::TYPE_NUMBER, 'group' => 'Signals'],
    ];

    /**
     * Latest computed metrics per asset, joined in by symbol.
     *
     * @var array<string, array{label: string, type: string, group: string}>
     */
    private const METRICS = [
        'close' => ['label' => 'Close', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'ma50' => ['label' => 'MA50', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'ma100' => ['label' => 'MA100', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'high20' => ['label' => 'High (20d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'high55' => ['label' => 'High (55d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'atr14' => ['label' => 'ATR14', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'roc13' => ['label' => 'ROC13', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'avg_vol20' => ['label' => 'Average volume (20d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'vol_vs_avg20' => ['label' => 'Volume vs average (20d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'close_vs_high20' => ['label' => 'Close vs high (20d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'close_vs_high55' => ['label' => 'Close vs high (55d)', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'uptrend' => ['label' => 'Uptrend', 'type' => self::TYPE_BOOLEAN, 'group' => 'Metrics'],
        'bars' => ['label' => 'Bars of history', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
        'bavg' => ['label' => 'BAVG', 'type' => self::TYPE_NUMBER, 'group' => 'Metrics'],
    ];

    /**
     * Every referenceable field, keyed as "<source>.<column>".
     *
     * @return array<string, array{label: string, type: string, group: string, source: string, column: string}>
     */
    public static function all(): array
    {
        static $fields = null;

        if ($fields !== null) {
            return $fields;
        }

        $fields = [];

        foreach (self::FEATURES as $column => $meta) {
            $fields["features.{$column}"] = $meta + ['source' => 'features', 'column' => $column];
        }

        foreach (self::METRICS as $column => $meta) {
            $fields["metrics.{$column}"] = $meta + ['source' => 'metrics', 'column' => $column];
        }

        return $fields;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array{label: string, type: string, group: string, source: string, column: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function typeOf(string $key): ?string
    {
        return self::get($key)['type'] ?? null;
    }

    /**
     * The registry shaped for a UI field picker, grouped by category.
     *
     * @return array<int, array{key: string, label: string, type: string, group: string}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::all() as $key => $meta) {
            $catalog[] = [
                'key' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'group' => $meta['group'],
            ];
        }

        return $catalog;
    }

    /**
     * Column lists for the runner's SELECT, so it reads only what rules can use.
     *
     * @return array<int, string>
     */
    public static function columnsFor(string $source): array
    {
        $columns = [];

        foreach (self::all() as $meta) {
            if ($meta['source'] === $source) {
                $columns[] = $meta['column'];
            }
        }

        return array_values(array_unique($columns));
    }
}

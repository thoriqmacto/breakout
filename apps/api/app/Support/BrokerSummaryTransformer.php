<?php

namespace App\Support;

final class BrokerSummaryTransformer
{
    /**
     * @return array<int, array{symbol:string,date:string,broker:string,net_value:float,buy_value:float,sell_value:float}>
     */
    public static function toRows(string $symbol, array $json): array
    {
        $rows = [];

        $candidates = self::extractCandidates($json);
        $defaultDate = self::extractSummaryFromDate($json);

        foreach ($candidates as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $date = $defaultDate ?? (self::firstNonEmpty(
                $entry,
                ['date', 'trading_date', 'traded_at', 'day', 'session_date', 'netbs_date']
            ) ?? '');
            $broker = self::firstNonEmpty($entry, ['broker', 'broker_code', 'code', 'brokerName', 'netbs_broker_code']) ?? '';

            $buy = self::num(self::firstNonEmpty($entry, ['buy_value', 'buyValue', 'total_buy', 'buy', 'bval']));
            $sell = self::num(self::firstNonEmpty($entry, ['sell_value', 'sellValue', 'total_sell', 'sell', 'sval']));
            $net = self::num(self::firstNonEmpty($entry, ['net_value', 'netValue', 'net']));

            if (($buy === null || $sell === null) && isset($entry['values']) && is_array($entry['values'])) {
                $buy = $buy ?? self::num($entry['values']['buy'] ?? $entry['values']['buy_value'] ?? null);
                $sell = $sell ?? self::num($entry['values']['sell'] ?? $entry['values']['sell_value'] ?? null);
            }

            if ($net === null) {
                $net = ($buy ?? 0) - ($sell ?? 0);
            }

            if (($broker === '' || $date === '') && isset($entry['broker']) && is_array($entry['broker'])) {
                $broker = $broker ?: (string) ($entry['broker']['code'] ?? $entry['broker']['id'] ?? '');
            }

            if ($date === '' && isset($entry['meta']) && is_array($entry['meta'])) {
                $date = (string) ($entry['meta']['date'] ?? $entry['meta']['trading_date'] ?? '');
            }

            if ($broker === '' || $date === '') {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'date' => self::normalizeDate($date),
                'broker' => (string) $broker,
                'net_value' => (float) ($net ?? 0),
                'buy_value' => (float) ($buy ?? 0),
                'sell_value' => (float) ($sell ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function toFacts(string $symbol, array $json, ?string $transactionType = null): array
    {
        $summary = self::extractBrokerSummaryPayload($json);
        if ($summary === []) {
            return [];
        }

        $transactionType = self::extractTransactionType($json, $summary, $transactionType);
        $defaultDate = self::extractSummaryFromDate($json, $summary);

        $byBroker = [];

        $brokersBuy = $summary['brokers_buy'] ?? [];
        if (is_array($brokersBuy)) {
            foreach ($brokersBuy as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $broker = (string) ($entry['netbs_broker_code'] ?? $entry['broker_code'] ?? $entry['broker'] ?? '');
                $date = $defaultDate
                    ?? self::normalizeDate((string) ($entry['netbs_date'] ?? $entry['date'] ?? ''));
                if ($broker === '' || $date === '') {
                    continue;
                }

                $key = $broker.'|'.$date;
                $byBroker[$key] ??= self::emptyFact($symbol, $broker, $date, $transactionType);

                $byBroker[$key]['broker_type'] = $byBroker[$key]['broker_type']
                    ?? self::stringOrNull($entry['netbs_broker_type'] ?? $entry['broker_type'] ?? null);
                $byBroker[$key]['buy_lot'] = self::int($entry['blot'] ?? $entry['buy_lot'] ?? null) ?? 0;
                $byBroker[$key]['buy_volume'] = self::int($entry['blotv'] ?? $entry['buy_volume'] ?? null) ?? 0;
                $byBroker[$key]['buy_value'] = self::decimal($entry['bval'] ?? $entry['buy_value'] ?? null) ?? 0.0;
                $byBroker[$key]['buy_value_v'] = self::decimal($entry['bvalv'] ?? $entry['buy_value_v'] ?? null) ?? 0.0;
                $byBroker[$key]['buy_avg_price'] = self::decimal($entry['netbs_buy_avg_price'] ?? $entry['buy_avg_price'] ?? null);
            }
        }

        $brokersSell = $summary['brokers_sell'] ?? [];
        if (is_array($brokersSell)) {
            foreach ($brokersSell as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $broker = (string) ($entry['netbs_broker_code'] ?? $entry['broker_code'] ?? $entry['broker'] ?? '');
                $date = $defaultDate
                    ?? self::normalizeDate((string) ($entry['netbs_date'] ?? $entry['date'] ?? ''));
                if ($broker === '' || $date === '') {
                    continue;
                }

                $key = $broker.'|'.$date;
                $byBroker[$key] ??= self::emptyFact($symbol, $broker, $date, $transactionType);

                $byBroker[$key]['broker_type'] = $byBroker[$key]['broker_type']
                    ?? self::stringOrNull($entry['netbs_broker_type'] ?? $entry['broker_type'] ?? null);
                $byBroker[$key]['sell_lot'] = self::absInt($entry['slot'] ?? $entry['sell_lot'] ?? null) ?? 0;
                $byBroker[$key]['sell_volume'] = self::absInt($entry['slotv'] ?? $entry['sell_volume'] ?? null) ?? 0;
                $byBroker[$key]['sell_value'] = self::absDecimal($entry['sval'] ?? $entry['sell_value'] ?? null) ?? 0.0;
                $byBroker[$key]['sell_value_v'] = self::absDecimal($entry['svalv'] ?? $entry['sell_value_v'] ?? null) ?? 0.0;
                $byBroker[$key]['sell_avg_price'] = self::decimal($entry['netbs_sell_avg_price'] ?? $entry['sell_avg_price'] ?? null);
            }
        }

        foreach ($byBroker as &$fact) {
            $fact['net_lot'] = (int) ($fact['buy_lot'] ?? 0) - (int) ($fact['sell_lot'] ?? 0);
            $fact['net_volume'] = (int) ($fact['buy_volume'] ?? 0) - (int) ($fact['sell_volume'] ?? 0);
            $fact['net_value'] = (float) ($fact['buy_value'] ?? 0) - (float) ($fact['sell_value'] ?? 0);
        }
        unset($fact);

        return array_values($byBroker);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function toBandarDetectorSummary(
        array $json,
        ?string $fromDate,
        ?string $toDate,
        ?string $transactionType = null,
    ): ?array {
        $detector = self::extractBandarDetectorPayload($json);
        if ($detector === null) {
            return null;
        }

        $transactionType = self::extractTransactionType($json, [], $transactionType);
        $metricsKeys = ['avg', 'avg5', 'top1', 'top3', 'top5', 'top10'];
        $metrics = [];

        foreach ($metricsKeys as $key) {
            if (isset($detector[$key]) && is_array($detector[$key])) {
                $metrics[$key] = $detector[$key];
            }
        }

        return [
            'from_date' => $fromDate ? self::normalizeDate($fromDate) : null,
            'to_date' => $toDate ? self::normalizeDate($toDate) : null,
            'transaction_type' => $transactionType,
            'broker_accdist' => self::stringOrNull($detector['broker_accdist'] ?? null),
            'number_broker_buysell' => self::int($detector['number_broker_buysell'] ?? null),
            'total_buyer' => self::int($detector['total_buyer'] ?? null),
            'total_seller' => self::int($detector['total_seller'] ?? null),
            'value' => self::decimal($detector['value'] ?? null),
            'volume' => self::int($detector['volume'] ?? null),
            'average_price' => self::decimal($detector['average'] ?? null),
            'metrics_json' => $metrics === [] ? null : $metrics,
        ];
    }

    private static function extractCandidates(array $json): array
    {
        $brokerSummary = self::extractBrokerSummaryEntries($json);
        if ($brokerSummary !== []) {
            return $brokerSummary;
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        if (isset($json['items']) && is_array($json['items'])) {
            return $json['items'];
        }

        if (isset($json['result']) && is_array($json['result'])) {
            return $json['result'];
        }

        if (array_is_list($json)) {
            return $json;
        }

        return [];
    }

    private static function extractBrokerSummaryEntries(array $json): array
    {
        if (
            ! isset($json['data'])
            || ! is_array($json['data'])
            || ! isset($json['data']['broker_summary'])
            || ! is_array($json['data']['broker_summary'])
        ) {
            return [];
        }

        $summary = $json['data']['broker_summary'];
        $entries = [];

        if (isset($summary['brokers_buy']) && is_array($summary['brokers_buy'])) {
            foreach ($summary['brokers_buy'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entries[] = [
                    'netbs_broker_code' => $entry['netbs_broker_code'] ?? null,
                    'netbs_date' => $entry['netbs_date'] ?? null,
                    'bval' => $entry['bval'] ?? $entry['bvalv'] ?? null,
                    'sval' => 0,
                ];
            }
        }

        if (isset($summary['brokers_sell']) && is_array($summary['brokers_sell'])) {
            foreach ($summary['brokers_sell'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entries[] = [
                    'netbs_broker_code' => $entry['netbs_broker_code'] ?? null,
                    'netbs_date' => $entry['netbs_date'] ?? null,
                    'bval' => 0,
                    'sval' => isset($entry['sval']) ? abs((float) $entry['sval']) : ($entry['svalv'] ?? null),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractBrokerSummaryPayload(array $json): array
    {
        if (isset($json['data']['broker_summary']) && is_array($json['data']['broker_summary'])) {
            return $json['data']['broker_summary'];
        }

        if (isset($json['broker_summary']) && is_array($json['broker_summary'])) {
            return $json['broker_summary'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function extractSummaryFromDate(array $json, array $summary = []): ?string
    {
        $candidates = [
            $summary['from'] ?? null,
            $summary['from_date'] ?? null,
            $json['data']['from'] ?? null,
            $json['data']['from_date'] ?? null,
            $json['from'] ?? null,
            $json['from_date'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return self::normalizeDate($candidate);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function extractBandarDetectorPayload(array $json): ?array
    {
        if (isset($json['data']['bandar_detector']) && is_array($json['data']['bandar_detector'])) {
            return $json['data']['bandar_detector'];
        }

        if (isset($json['bandar_detector']) && is_array($json['bandar_detector'])) {
            return $json['bandar_detector'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function extractTransactionType(array $json, array $summary, ?string $fallback): ?string
    {
        foreach ([
            $summary['transaction_type'] ?? null,
            $json['data']['transaction_type'] ?? null,
            $json['transaction_type'] ?? null,
            $fallback,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyFact(string $symbol, string $broker, string $date, ?string $transactionType): array
    {
        return [
            'symbol' => $symbol,
            'trade_date' => $date,
            'broker_code' => $broker,
            'transaction_type' => $transactionType,
            'broker_type' => null,
            'buy_lot' => 0,
            'buy_volume' => 0,
            'buy_value' => 0.0,
            'buy_value_v' => 0.0,
            'buy_avg_price' => null,
            'sell_lot' => 0,
            'sell_volume' => 0,
            'sell_value' => 0.0,
            'sell_value_v' => 0.0,
            'sell_avg_price' => null,
            'net_lot' => 0,
            'net_volume' => 0,
            'net_value' => 0.0,
        ];
    }

    private static function firstNonEmpty(array $entry, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $entry)) {
                continue;
            }

            $value = $entry[$key];

            if (is_array($value)) {
                continue;
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        $num = self::num($value);

        return $num === null ? null : (int) round($num);
    }

    private static function absInt(mixed $value): ?int
    {
        $num = self::int($value);

        return $num === null ? null : abs($num);
    }

    private static function decimal(mixed $value): ?float
    {
        return self::num($value);
    }

    private static function absDecimal(mixed $value): ?float
    {
        $num = self::decimal($value);

        return $num === null ? null : abs($num);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            return $date;
        }

        if (ctype_digit($date)) {
            if (strlen($date) === 8) {
                $parsed = \DateTimeImmutable::createFromFormat('Ymd', $date);
                if ($parsed instanceof \DateTimeImmutable) {
                    return $parsed->format('Y-m-d');
                }
            }

            $timestamp = (int) $date;
            if ($timestamp > 1_000_000_000_000) {
                $timestamp = (int) round($timestamp / 1000);
            }

            return date('Y-m-d', $timestamp);
        }

        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return $date;
    }
}

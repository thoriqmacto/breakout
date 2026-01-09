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

        foreach ($candidates as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $date   = self::firstNonEmpty($entry, ['date', 'trading_date', 'traded_at', 'day', 'session_date', 'netbs_date']) ?? '';
            $broker = self::firstNonEmpty($entry, ['broker', 'broker_code', 'code', 'brokerName', 'netbs_broker_code']) ?? '';

            $buy   = self::num(self::firstNonEmpty($entry, ['buy_value', 'buyValue', 'total_buy', 'buy', 'bval']));
            $sell  = self::num(self::firstNonEmpty($entry, ['sell_value', 'sellValue', 'total_sell', 'sell', 'sval']));
            $net   = self::num(self::firstNonEmpty($entry, ['net_value', 'netValue', 'net']));

            if (($buy === null || $sell === null) && isset($entry['values']) && is_array($entry['values'])) {
                $buy  = $buy  ?? self::num($entry['values']['buy']  ?? $entry['values']['buy_value']  ?? null);
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
                'symbol'     => $symbol,
                'date'       => self::normalizeDate($date),
                'broker'     => (string) $broker,
                'net_value'  => (float) ($net   ?? 0),
                'buy_value'  => (float) ($buy   ?? 0),
                'sell_value' => (float) ($sell  ?? 0),
            ];
        }

        return $rows;
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
            !isset($json['data'])
            || !is_array($json['data'])
            || !isset($json['data']['broker_summary'])
            || !is_array($json['data']['broker_summary'])
        ) {
            return [];
        }

        $summary = $json['data']['broker_summary'];
        $entries = [];

        if (isset($summary['brokers_buy']) && is_array($summary['brokers_buy'])) {
            foreach ($summary['brokers_buy'] as $entry) {
                if (!is_array($entry)) {
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
                if (!is_array($entry)) {
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

    private static function firstNonEmpty(array $entry, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $entry)) {
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

    private static function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            return $date;
        }

        if (ctype_digit($date)) {
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

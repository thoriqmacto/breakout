<?php

namespace App\Services\Reconciliation;

use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Services\Automation\TradingWeekResolver;
use App\Support\BrokerFlow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds one asset's reconciliation document.
 *
 * The document is a *derived* view. Everything in it comes from canonical
 * data -- price_bars, broker_summary_windows and their entries and detector
 * summaries, and the historical CSV -- so it can be deleted and rebuilt at
 * any time, and nothing in it is the only surviving copy of anything. That is
 * what lets the raw archive stay authoritative while this layer takes over
 * the recovery path.
 *
 * Two properties matter more than the field list.
 *
 * It is deterministic. Same inputs, byte-identical output, every time. Every
 * collection is sorted on a total order rather than on whatever the database
 * returned, because "has this changed?" is answered by comparing hashes, and
 * a hash that moves when the row order moves would rebuild and re-upload the
 * whole universe on a whim.
 *
 * It distinguishes a genuine single-day broker window from a range aggregate,
 * everywhere, without exception. A three-month aggregate is a legitimate
 * archive record and a perfectly good observation at its own length; what it
 * is not is ninety daily observations. Only `from_date === to_date` windows
 * reach `daily_flow`, so nothing downstream can mistake an aggregate for a
 * day the market actually traded that way.
 */
class AssetReconciler
{
    public function __construct(
        private readonly TradingWeekResolver $calendar,
        private readonly ReconciliationStore $store,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Asset $asset, string $fingerprint, ?Carbon $asOf = null): array
    {
        $symbol = strtoupper((string) $asset->symbol);
        $generatedAt = Carbon::now($this->calendar->timezone());
        $asOfDate = $asOf?->copy() ?? $generatedAt->copy();

        $ohlcv = $this->ohlcv($asset);
        $windows = $this->windows($asset);
        $dailyFlow = $this->dailyFlow($windows);
        $csv = $this->historicalCsv($symbol);

        $coverage = $this->coverage($ohlcv, $windows, $dailyFlow, $csv);
        $integrity = $this->integrity($asset, $ohlcv, $windows, $dailyFlow, $csv);
        $insight = $this->insight($ohlcv, $dailyFlow);

        return [
            'schema_version' => (int) config('reconciliation.schema_version', 1),
            'symbol' => $symbol,
            'generated_at' => $generatedAt->toIso8601String(),
            'as_of_trading_date' => $asOfDate->toDateString(),
            'source_fingerprint' => $fingerprint,
            'asset' => [
                'symbol' => $symbol,
                'name' => $asset->name,
                'sector' => $asset->sector,
                'sync_price' => (bool) $asset->sync_price,
                'sync_broker_summary' => (bool) $asset->sync_broker_summary,
            ],
            'coverage' => $coverage,
            'integrity' => $integrity,
            'ohlcv' => $ohlcv,
            'broker_summary' => [
                'windows' => $windows,
                'daily_flow' => $dailyFlow,
            ],
            'insight' => $insight,
        ];
    }

    /**
     * Every stored bar, ascending by date.
     *
     * Values are carried through as they are stored. Rounding here would make
     * a restored bar differ from the one it replaced, which is the one thing a
     * recovery format may not do.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ohlcv(Asset $asset): array
    {
        $rows = [];

        DB::table('price_bars')
            ->where('asset_id', $asset->id)
            ->orderBy('date')
            ->orderBy('id')
            ->select(['date', 'open', 'high', 'low', 'close', 'volume'])
            ->chunk(2000, function ($chunk) use (&$rows): void {
                foreach ($chunk as $row) {
                    $rows[] = [
                        'date' => Carbon::parse((string) $row->date)->toDateString(),
                        'open' => $this->number($row->open),
                        'high' => $this->number($row->high),
                        'low' => $this->number($row->low),
                        'close' => $this->number($row->close),
                        'volume' => $row->volume === null ? null : (int) $row->volume,
                    ];
                }
            });

        return $rows;
    }

    /**
     * Every broker-summary window, with its entries and detector summary.
     *
     * Ordered by (from_date, to_date, transaction_type), which is the window's
     * canonical identity -- the same tuple the importer keys on -- so the
     * order cannot depend on insertion history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function windows(Asset $asset): array
    {
        $models = BrokerSummaryWindow::query()
            ->where('asset_id', $asset->id)
            ->with(['entries', 'bandarDetectorSummary'])
            ->orderBy('from_date')
            ->orderBy('to_date')
            ->orderBy('transaction_type')
            ->get();

        $windows = [];

        foreach ($models as $window) {
            $from = $this->dateString($window->from_date);
            $to = $this->dateString($window->to_date);

            $windows[] = [
                'from_date' => $from,
                'to_date' => $to,
                // Stored rather than derived on read, so a consumer cannot
                // reach the wrong answer by comparing the dates differently.
                'is_single_day' => $from !== null && $from === $to,
                'transaction_type' => (string) $window->transaction_type,
                'market_board' => $window->market_board,
                'investor_type' => $window->investor_type,
                'requested_limit' => $window->requested_limit === null ? null : (int) $window->requested_limit,
                'source_filename' => $window->source_filename,
                'source_hash' => $window->source_hash,
                'imported_at' => $window->imported_at === null
                    ? null
                    : Carbon::parse((string) $window->imported_at)->toIso8601String(),
                'coverage' => [
                    'returned_buyer_count' => $this->integer($window->returned_buyer_count),
                    'returned_seller_count' => $this->integer($window->returned_seller_count),
                    'total_buyer' => $this->integer($window->total_buyer),
                    'total_seller' => $this->integer($window->total_seller),
                ],
                'bandar_detector' => $this->detector($window),
                'entries' => $this->entries($window),
            ];
        }

        return $windows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detector(BrokerSummaryWindow $window): ?array
    {
        $detector = $window->bandarDetectorSummary;

        if ($detector === null) {
            return null;
        }

        return [
            'broker_accdist' => $detector->broker_accdist,
            'accdist_score' => BrokerFlow::score($detector->broker_accdist),
            'number_broker_buysell' => $this->integer($detector->number_broker_buysell),
            'total_buyer' => $this->integer($detector->total_buyer),
            'total_seller' => $this->integer($detector->total_seller),
            'value' => $this->number($detector->value),
            'volume' => $this->integer($detector->volume),
            'average_price' => $this->number($detector->average_price),
            'metrics_json' => $detector->metrics_json,
        ];
    }

    /**
     * A window's broker rows, on a total order.
     *
     * Side then broker code then source date: broker code alone is not unique
     * within a window -- the same house appears on the buy and the sell list
     * -- and a partial order would let two equal-ranked rows swap between
     * runs and change the document's hash for no reason.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries(BrokerSummaryWindow $window): array
    {
        $entries = $window->entries->all();

        usort($entries, static function ($a, $b): int {
            return [(string) $a->side, (string) $a->broker_code, (string) $a->source_date, (int) $a->id]
                <=> [(string) $b->side, (string) $b->broker_code, (string) $b->source_date, (int) $b->id];
        });

        return array_map(fn ($entry): array => [
            'broker_code' => (string) $entry->broker_code,
            'side' => (string) $entry->side,
            'broker_type' => $entry->broker_type,
            'frequency' => $this->integer($entry->frequency),
            'source_date' => $this->dateString($entry->source_date),
            'net_lot' => $this->integer($entry->net_lot),
            'net_value' => $this->number($entry->net_value),
            'gross_volume' => $this->integer($entry->gross_volume),
            'gross_value' => $this->number($entry->gross_value),
            'average_price' => $this->number($entry->average_price),
        ], $entries);
    }

    /**
     * The daily broker trajectory: genuine single-day windows only.
     *
     * The filter is the point of the whole structure. A range aggregate
     * describes flow over its range and says nothing about the path through
     * it, so expanding one into daily observations would invent data. Windows
     * that fail `is_single_day` are kept in `windows` -- they are real
     * archive records -- and simply never appear here.
     *
     * @param  array<int, array<string, mixed>>  $windows
     * @return array<int, array<string, mixed>>
     */
    private function dailyFlow(array $windows): array
    {
        $byDate = [];

        foreach ($windows as $window) {
            if ($window['is_single_day'] !== true || $window['bandar_detector'] === null) {
                continue;
            }

            $detector = $window['bandar_detector'];
            $date = (string) $window['from_date'];

            // One observation per date. A symbol collected under two
            // transaction types would otherwise contribute twice to a flow
            // balance that is supposed to count sessions.
            $byDate[$date] = [
                'date' => $date,
                'transaction_type' => $window['transaction_type'],
                'broker_accdist' => $detector['broker_accdist'],
                'accdist_score' => $detector['accdist_score'],
                'number_broker_buysell' => $detector['number_broker_buysell'],
                'turnover_value' => $detector['value'],
                'turnover_volume' => $detector['volume'],
                'average_price' => $detector['average_price'],
            ];
        }

        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @param  array<int, array<string, mixed>>  $windows
     * @param  array<int, array<string, mixed>>  $dailyFlow
     * @param  array{path: string, exists: bool, hash: ?string, size: ?int}  $csv
     * @return array<string, mixed>
     */
    private function coverage(array $ohlcv, array $windows, array $dailyFlow, array $csv): array
    {
        $singleDay = 0;

        foreach ($windows as $window) {
            if ($window['is_single_day'] === true) {
                $singleDay++;
            }
        }

        $froms = array_column($windows, 'from_date');
        $tos = array_column($windows, 'to_date');

        return [
            'ohlcv' => [
                'first_date' => $ohlcv === [] ? null : $ohlcv[0]['date'],
                'last_date' => $ohlcv === [] ? null : $ohlcv[array_key_last($ohlcv)]['date'],
                'rows' => count($ohlcv),
                'source_path' => $csv['path'],
                'source_exists' => $csv['exists'],
                'source_hash' => $csv['hash'],
                'source_size' => $csv['size'],
            ],
            'broker_summary' => [
                'first_window_from' => $froms === [] ? null : min($froms),
                'last_window_to' => $tos === [] ? null : max($tos),
                'window_count' => count($windows),
                'single_day_window_count' => $singleDay,
                'aggregate_window_count' => count($windows) - $singleDay,
                'latest_single_day' => $dailyFlow === [] ? null : $dailyFlow[array_key_last($dailyFlow)]['date'],
                'daily_flow_sessions' => count($dailyFlow),
            ],
        ];
    }

    /**
     * Explicit, actionable conditions -- never a vibe.
     *
     * Every entry names something a person could go and fix. Absence of data
     * is not automatically corruption: a suspended or newly listed stock
     * legitimately has no recent bar, and the honest report there is a
     * coverage gap rather than an error.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @param  array<int, array<string, mixed>>  $windows
     * @param  array<int, array<string, mixed>>  $dailyFlow
     * @param  array{path: string, exists: bool, hash: ?string, size: ?int}  $csv
     * @return array<string, mixed>
     */
    private function integrity(Asset $asset, array $ohlcv, array $windows, array $dailyFlow, array $csv): array
    {
        $cap = max(1, (int) config('reconciliation.max_reported_items', 50));

        $warnings = [];
        $errors = [];

        $duplicateDates = $this->duplicateDates($ohlcv);
        $invalidRanges = [];
        $duplicateWindows = [];
        $missingSources = [];

        $seenWindows = [];

        foreach ($windows as $window) {
            $from = $window['from_date'];
            $to = $window['to_date'];

            if ($from === null || $to === null || $from > $to) {
                $invalidRanges[] = sprintf('%s..%s', (string) $from, (string) $to);

                continue;
            }

            $identity = $from.'|'.$to.'|'.$window['transaction_type'];

            if (isset($seenWindows[$identity])) {
                $duplicateWindows[] = $identity;
            }

            $seenWindows[$identity] = true;

            if ($window['source_filename'] !== null && ! $this->rawExists((string) $window['source_filename'])) {
                $missingSources[] = (string) $window['source_filename'];
            }
        }

        $missingSessions = $this->missingBrokerSessions($asset, $ohlcv, $dailyFlow);

        if (! $csv['exists'] && (bool) $asset->sync_price) {
            $errors[] = sprintf('The historical CSV %s is missing, so OHLCV cannot be restored from the archive.', $csv['path']);
        }

        if ($duplicateDates !== []) {
            $errors[] = sprintf('%d duplicate OHLCV date(s), starting %s.', count($duplicateDates), $duplicateDates[0]);
        }

        if ($invalidRanges !== []) {
            $errors[] = sprintf('%d broker window(s) have a from_date after their to_date.', count($invalidRanges));
        }

        if ($duplicateWindows !== []) {
            $errors[] = sprintf('%d duplicate broker window identity/identities.', count($duplicateWindows));
        }

        if ($missingSources !== []) {
            $warnings[] = sprintf(
                '%d broker window(s) reference a raw file that is no longer in the archive, so they cannot be reprocessed forensically.',
                count($missingSources),
            );
        }

        if ($ohlcv === [] && (bool) $asset->sync_price) {
            $warnings[] = 'No OHLCV bars are stored for this asset.';
        }

        if ($windows === [] && (bool) $asset->sync_broker_summary) {
            $warnings[] = 'No broker-summary windows are stored for this asset.';
        }

        if ($missingSessions !== []) {
            $warnings[] = sprintf(
                '%d recent trading session(s) have an OHLCV bar but no single-day broker summary.',
                count($missingSessions),
            );
        }

        $lag = $this->brokerLagSessions($ohlcv, $dailyFlow);

        if ($lag !== null && (bool) $asset->sync_broker_summary) {
            $errorAfter = max(1, (int) config('reconciliation.broker_lag_error_sessions', 5));
            $warnAfter = max(1, (int) config('reconciliation.broker_lag_warning_sessions', 2));

            if ($lag >= $errorAfter) {
                $errors[] = sprintf('Daily broker data trails the latest bar by %d trading session(s).', $lag);
            } elseif ($lag >= $warnAfter) {
                $warnings[] = sprintf('Daily broker data trails the latest bar by %d trading session(s).', $lag);
            }
        }

        return [
            'status' => $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'healthy'),
            'warnings' => $warnings,
            'errors' => $errors,
            'missing_broker_sessions' => array_slice($missingSessions, 0, $cap),
            'missing_broker_session_count' => count($missingSessions),
            'duplicate_ohlcv_dates' => array_slice($duplicateDates, 0, $cap),
            'invalid_broker_ranges' => array_slice($invalidRanges, 0, $cap),
            'duplicate_broker_windows' => array_slice($duplicateWindows, 0, $cap),
            'missing_source_files' => array_slice($missingSources, 0, $cap),
            'broker_lag_sessions' => $lag,
        ];
    }

    /**
     * Sessions the market traded, this asset has a bar for, and no single-day
     * broker summary covers.
     *
     * Anchored on the asset's own bars rather than on the exchange calendar
     * alone, so a weekend, a holiday, or a day the stock was suspended is
     * never reported as a gap -- there is no bar, so there is nothing missing.
     * Bounded to a recent window, because sessions before daily collection
     * began are history, not gaps.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @param  array<int, array<string, mixed>>  $dailyFlow
     * @return array<int, string>
     */
    private function missingBrokerSessions(Asset $asset, array $ohlcv, array $dailyFlow): array
    {
        if (! (bool) $asset->sync_broker_summary || $dailyFlow === []) {
            // Nothing to be behind on: an asset with no daily history at all
            // has not started daily collection, which the coverage block
            // already reports without calling it a gap.
            return [];
        }

        $lookback = max(1, (int) config('reconciliation.missing_session_lookback', 30));
        $recentBars = array_slice($ohlcv, -$lookback);

        $covered = array_flip(array_column($dailyFlow, 'date'));

        // Never before this asset's first daily observation: earlier sessions
        // predate daily collection.
        $firstDaily = $dailyFlow[0]['date'];
        $latestDaily = $dailyFlow[array_key_last($dailyFlow)]['date'];

        $missing = [];

        foreach ($recentBars as $bar) {
            $date = (string) $bar['date'];

            if ($date < $firstDaily || $date > $latestDaily) {
                continue;
            }

            if (! isset($covered[$date])) {
                $missing[] = $date;
            }
        }

        return $missing;
    }

    /**
     * How many of the asset's own recent sessions sit after its newest daily
     * broker observation.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @param  array<int, array<string, mixed>>  $dailyFlow
     */
    private function brokerLagSessions(array $ohlcv, array $dailyFlow): ?int
    {
        if ($ohlcv === [] || $dailyFlow === []) {
            return null;
        }

        $latestDaily = (string) $dailyFlow[array_key_last($dailyFlow)]['date'];
        $lag = 0;

        foreach (array_reverse($ohlcv) as $bar) {
            if ((string) $bar['date'] <= $latestDaily) {
                break;
            }

            $lag++;
        }

        return $lag;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @return array<int, string>
     */
    private function duplicateDates(array $ohlcv): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($ohlcv as $bar) {
            $date = (string) $bar['date'];

            if (isset($seen[$date])) {
                $duplicates[$date] = $date;
            }

            $seen[$date] = true;
        }

        return array_values($duplicates);
    }

    /**
     * Descriptive context, never a recommendation.
     *
     * The session counts travel with the balances for the reason the balances
     * exist at all: three accumulating sessions out of five available is a
     * different statement from three out of five observed, and a reader given
     * only "+3" cannot tell which they are looking at.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     * @param  array<int, array<string, mixed>>  $dailyFlow
     * @return array<string, mixed>
     */
    private function insight(array $ohlcv, array $dailyFlow): array
    {
        $scores = array_map(static fn (array $row): int => (int) $row['accdist_score'], $dailyFlow);
        $windows = (array) config('reconciliation.flow_windows', [5, 20]);

        $insight = [
            'latest_broker_date' => $dailyFlow === [] ? null : $dailyFlow[array_key_last($dailyFlow)]['date'],
            'latest_accdist' => $dailyFlow === [] ? null : $dailyFlow[array_key_last($dailyFlow)]['broker_accdist'],
            'latest_accdist_score' => $dailyFlow === [] ? null : (int) $dailyFlow[array_key_last($dailyFlow)]['accdist_score'],
            'daily_sessions_total' => count($dailyFlow),
        ];

        foreach ($windows as $window) {
            $window = (int) $window;
            $balance = BrokerFlow::balance($scores, $window);

            $insight['flow_balance_'.$window.'d'] = $balance['balance'];
            $insight['available_daily_sessions_'.$window.'d'] = $balance['available'];
            $insight['price_return_'.$window.'d'] = $this->priceReturn($ohlcv, $window);
        }

        return $insight;
    }

    /**
     * Close-to-close return over the last N stored bars, as a fraction.
     *
     * @param  array<int, array<string, mixed>>  $ohlcv
     */
    private function priceReturn(array $ohlcv, int $sessions): ?float
    {
        if (count($ohlcv) < $sessions + 1) {
            return null;
        }

        $last = $ohlcv[array_key_last($ohlcv)]['close'];
        $prior = $ohlcv[count($ohlcv) - 1 - $sessions]['close'];

        if ($last === null || $prior === null || (float) $prior === 0.0) {
            return null;
        }

        return round(((float) $last - (float) $prior) / (float) $prior, 6);
    }

    /**
     * @return array{path: string, exists: bool, hash: ?string, size: ?int}
     */
    private function historicalCsv(string $symbol): array
    {
        $directory = rtrim((string) config('csv.seed_dir'), '/');
        $path = $directory.'/'.$symbol.'.csv';

        if (! is_file($path) || ! is_readable($path)) {
            return ['path' => $path, 'exists' => false, 'hash' => null, 'size' => null];
        }

        $hash = hash_file('sha256', $path);

        return [
            'path' => $path,
            'exists' => true,
            'hash' => $hash === false ? null : $hash,
            'size' => filesize($path) ?: null,
        ];
    }

    private function rawExists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

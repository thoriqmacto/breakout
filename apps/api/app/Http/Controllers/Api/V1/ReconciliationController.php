<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Http\Request;
use JsonException;

/**
 * The reconciliation list and per-asset detail.
 *
 * Split from backup-status because the two answer different questions at
 * different costs, and because the list is bounded: reading several hundred
 * full documents to render a table would defeat the manifest's whole purpose.
 * The list reads the manifest only; a document is opened when -- and only
 * when -- the reader asks for one asset.
 */
class ReconciliationController extends ApiController
{
    private const MAX_PER_PAGE = 200;

    public function index(Request $request, ReconciliationStore $store)
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', 'in:healthy,warning,error'],
            'filter' => ['sometimes', 'string', 'in:stale_broker,missing_ohlcv,with_gaps,accumulating,distributing,insufficient_daily'],
            'sort' => ['sometimes', 'string', 'in:symbol,integrity_status,ohlcv_last,latest_broker_daily,gap_count,flow_balance,price_return,ohlcv_rows'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $manifest = $store->readManifest();
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];

        $window = (int) (config('reconciliation.flow_windows', [5, 20])[0] ?? 5);

        $rows = [];

        foreach ($assets as $symbol => $entry) {
            $rows[] = array_merge(['symbol' => (string) $symbol], $entry);
        }

        $rows = $this->filter($rows, $data, $window);
        $rows = $this->sort($rows, $data, $window);

        $perPage = (int) ($data['per_page'] ?? 50);
        $page = (int) ($data['page'] ?? 1);
        $total = count($rows);

        return ApiResponse::success([
            'generated_at' => $manifest['generated_at'] ?? null,
            'market_date' => $manifest['market_date'] ?? null,
            'schema_version' => $manifest['schema_version'] ?? null,
            'summary' => $manifest['summary'] ?? [],
            'flow_window' => $window,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
        ]);
    }

    /**
     * One asset's full reconciliation document.
     *
     * The OHLCV series and the broker entries are the bulk of a document and
     * are not what a detail view shows, so they are summarised rather than
     * returned whole -- a fifteen-year history is megabytes, and the reader
     * wants coverage, health and the recent trajectory.
     */
    public function show(Request $request, ReconciliationStore $store, string $symbol)
    {
        $symbol = strtoupper(trim($symbol));

        if (preg_match('/^[A-Z0-9._-]{1,32}$/', $symbol) !== 1) {
            return ApiResponse::error('That is not a valid symbol.', 422);
        }

        try {
            $document = $store->readAsset($symbol);
        } catch (JsonException $exception) {
            return ApiResponse::error('The reconciliation document is not valid JSON: '.$exception->getMessage(), 422);
        }

        if ($document === null) {
            return ApiResponse::error('No reconciliation document exists for that symbol.', 404);
        }

        $sessions = max(1, (int) $request->integer('sessions', 20));

        $manifest = $store->readManifest();
        $entry = $manifest['assets'][$symbol] ?? null;

        $ohlcv = is_array($document['ohlcv'] ?? null) ? $document['ohlcv'] : [];
        $dailyFlow = is_array($document['broker_summary']['daily_flow'] ?? null)
            ? $document['broker_summary']['daily_flow']
            : [];
        $windows = is_array($document['broker_summary']['windows'] ?? null)
            ? $document['broker_summary']['windows']
            : [];

        return ApiResponse::success([
            'symbol' => $document['symbol'] ?? $symbol,
            'schema_version' => $document['schema_version'] ?? null,
            'generated_at' => $document['generated_at'] ?? null,
            'as_of_trading_date' => $document['as_of_trading_date'] ?? null,
            'source_fingerprint' => $document['source_fingerprint'] ?? null,
            'asset' => $document['asset'] ?? [],
            'coverage' => $document['coverage'] ?? [],
            'integrity' => $document['integrity'] ?? [],
            'insight' => $document['insight'] ?? [],
            'manifest_entry' => $entry,
            'document_hash' => $store->hashOf($store->assetPath($symbol)),
            'document_size' => $store->size($store->assetPath($symbol)),

            // Bounded views rather than the whole series.
            'recent_ohlcv' => array_slice($ohlcv, -$sessions),
            'recent_daily_flow' => array_slice($dailyFlow, -$sessions),
            'recent_windows' => array_map(
                static fn (array $window): array => [
                    'from_date' => $window['from_date'] ?? null,
                    'to_date' => $window['to_date'] ?? null,
                    'is_single_day' => $window['is_single_day'] ?? null,
                    'transaction_type' => $window['transaction_type'] ?? null,
                    'source_filename' => $window['source_filename'] ?? null,
                    'source_hash' => $window['source_hash'] ?? null,
                    'entry_count' => is_array($window['entries'] ?? null) ? count($window['entries']) : 0,
                    'broker_accdist' => $window['bandar_detector']['broker_accdist'] ?? null,
                ],
                array_slice($windows, -$sessions),
            ),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function filter(array $rows, array $data, int $window): array
    {
        $search = strtoupper(trim((string) ($data['search'] ?? '')));
        $status = $data['status'] ?? null;
        $filter = $data['filter'] ?? null;
        $balanceKey = 'flow_balance_'.$window.'d';
        $availableKey = 'available_daily_sessions_'.$window.'d';

        return array_values(array_filter($rows, static function (array $row) use ($search, $status, $filter, $balanceKey, $availableKey, $window): bool {
            if ($search !== '' && ! str_contains((string) $row['symbol'], $search)) {
                return false;
            }

            if ($status !== null && ($row['integrity_status'] ?? null) !== $status) {
                return false;
            }

            return match ($filter) {
                'stale_broker' => ($row['broker_lag_sessions'] ?? 0) > 0,
                'missing_ohlcv' => (int) ($row['ohlcv_rows'] ?? 0) === 0 || ($row['ohlcv_source_exists'] ?? true) === false,
                'with_gaps' => (int) ($row['gap_count'] ?? 0) > 0,
                'accumulating' => (int) ($row[$availableKey] ?? 0) >= $window && (int) ($row[$balanceKey] ?? 0) > 0,
                'distributing' => (int) ($row[$availableKey] ?? 0) >= $window && (int) ($row[$balanceKey] ?? 0) < 0,
                'insufficient_daily' => (int) ($row[$availableKey] ?? 0) < $window,
                default => true,
            };
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function sort(array $rows, array $data, int $window): array
    {
        $sort = (string) ($data['sort'] ?? 'symbol');
        $descending = (string) ($data['direction'] ?? 'asc') === 'desc';

        $key = match ($sort) {
            'flow_balance' => 'flow_balance_'.$window.'d',
            'price_return' => 'price_return_'.$window.'d',
            default => $sort,
        };

        usort($rows, static function (array $a, array $b) use ($key, $descending): int {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;

            // Nulls last in both directions: a symbol with no broker date is
            // not "the earliest", it is unknown, and sorting it to the top of
            // an ascending list would bury the answer the reader wanted.
            if ($left === null && $right === null) {
                return $a['symbol'] <=> $b['symbol'];
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            $result = $descending ? ($right <=> $left) : ($left <=> $right);

            return $result === 0 ? ($a['symbol'] <=> $b['symbol']) : $result;
        });

        return $rows;
    }
}

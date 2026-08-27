<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryFact;
use App\Models\BrokerSummaryWindow;
use App\Models\Broksum;
use App\Support\BrokerSummaryTransformer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BrokerSummaryImporter
{
    /**
     * Columns declared unsigned in the broker_summary_facts schema.
     *
     * The net_* columns are deliberately absent: they are signed, and a
     * negative net is the normal way to express distribution.
     */
    private const UNSIGNED_COLUMNS = [
        'buy_lot',
        'buy_volume',
        'buy_value',
        'buy_value_v',
        'sell_lot',
        'sell_volume',
        'sell_value',
        'sell_value_v',
    ];

    /**
     * Columns declared unsigned in the bandar_detector_summaries schema.
     *
     * number_broker_buysell is absent: it is buyers minus sellers and is
     * signed. It was declared unsigned once, and MariaDB rejected a normal
     * -27 with the same 1264 that this guard exists to explain -- but the fix
     * there was the column type, not the value, so listing it here would have
     * turned a schema bug into a spurious import failure.
     */
    private const UNSIGNED_DETECTOR_COLUMNS = [
        'total_buyer',
        'total_seller',
        'volume',
    ];

    /**
     * Largest value BIGINT UNSIGNED holds.
     */
    private const UNSIGNED_BIGINT_MAX = 18446744073709551615;

    /**
     * Fail with the offending record rather than letting the driver report a
     * row number in an anonymous chunk.
     *
     * MariaDB answers both a negative and an overflow with the same
     * SQLSTATE[22003] 1264 "Out of range value", so the message alone cannot
     * tell them apart -- this says which it is, and for which broker and date.
     *
     * @param  array<int, array<string, mixed>>  $payload
     * @param  array<int, string>  $columns
     * @param  string  $label  How to identify a row: its broker, or the window.
     */
    private function guardMagnitudes(
        string $symbol,
        array $payload,
        array $columns = self::UNSIGNED_COLUMNS,
        string $label = 'broker',
    ): void {
        foreach ($payload as $row) {
            $subject = $label === 'broker'
                ? sprintf('%s %s on %s', $symbol, $row['broker_code'] ?? '?', $row['trade_date'] ?? '?')
                : sprintf('%s for %s..%s', $symbol, $row['from_date'] ?? '?', $row['to_date'] ?? '?');

            foreach ($columns as $column) {
                $value = $row[$column] ?? 0;

                if (! is_int($value) && ! is_float($value)) {
                    continue;
                }

                if ($value < 0) {
                    throw new RuntimeException(sprintf(
                        '%s has a negative %s (%s). That column is unsigned, so the database will reject it.',
                        $subject,
                        $column,
                        $value,
                    ));
                }

                if ($value > self::UNSIGNED_BIGINT_MAX) {
                    throw new RuntimeException(sprintf(
                        '%s has a %s of %s, larger than the column can store. The source data looks wrong.',
                        $subject,
                        $column,
                        $value,
                    ));
                }
            }
        }
    }

    /**
     * Persist one retrieval window, its broker entries and its detector.
     *
     * Identity is (asset, from_date, to_date, transaction_type), so
     * 2026-05-26..2026-08-26 and 2026-05-26..2026-09-26 are different rows
     * rather than one overwriting the other, and re-importing the same file
     * updates in place instead of duplicating.
     *
     * @param  array<string, mixed>  $window
     */
    private function storeWindow(
        Asset $asset,
        array $window,
        string $path,
        string $contents,
        Carbon $timestamp,
    ): ?BrokerSummaryWindow {
        $from = $window['from_date'] ?? null;
        $to = $window['to_date'] ?? null;

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            Log::warning('Broker summary window skipped: the range could not be resolved.', [
                'symbol' => $window['symbol'],
                'file' => $path,
            ]);

            return null;
        }

        if ($from > $to) {
            Log::warning('Broker summary window skipped: from_date is after to_date.', [
                'symbol' => $window['symbol'],
                'from' => $from,
                'to' => $to,
                'file' => $path,
            ]);

            return null;
        }

        // The payload's range wins, but a filename that disagrees is worth
        // saying so about: it usually means the archive was renamed or the
        // request and response drifted apart. Silently picking one is how the
        // original bug went unnoticed for so long.
        $meta = $this->metaFromPath($path);
        $this->warnOnRangeMismatch($window, $meta, $path);

        $model = BrokerSummaryWindow::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'from_date' => $from,
                'to_date' => $to,
                'transaction_type' => $window['transaction_type'] ?? '',
            ],
            [
                'returned_buyer_count' => $window['coverage']['returned_buyer_count'],
                'returned_seller_count' => $window['coverage']['returned_seller_count'],
                'total_buyer' => $window['coverage']['total_buyer'],
                'total_seller' => $window['coverage']['total_seller'],
                'source_filename' => $path,
                'source_hash' => hash('sha256', $contents),
                'imported_at' => $timestamp,
            ],
        );

        // Replace rather than merge: a re-import is the authoritative view of
        // that window, and a broker that dropped out of the list must not
        // linger from the previous run.
        $model->entries()->delete();

        foreach (array_chunk($window['entries'], 500) as $chunk) {
            $model->entries()->createMany($chunk);
        }

        $detector = $window['bandar_detector'] ?? null;

        if (is_array($detector)) {
            // Handed over as an array: the model casts metrics_json, and
            // encoding it here first would store the JSON string itself.
            $metricsJson = $detector['metrics_json'];

            $this->guardMagnitudes(
                $window['symbol'],
                [$detector + ['from_date' => $from, 'to_date' => $to]],
                self::UNSIGNED_DETECTOR_COLUMNS,
                'window',
            );

            BandarDetectorSummary::updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'from_date' => $from,
                    'to_date' => $to,
                    'transaction_type' => $window['transaction_type'] ?? '',
                ],
                [
                    'broker_summary_window_id' => $model->id,
                    'broker_accdist' => $detector['broker_accdist'],
                    'number_broker_buysell' => $detector['number_broker_buysell'],
                    'total_buyer' => $detector['total_buyer'],
                    'total_seller' => $detector['total_seller'],
                    'value' => $detector['value'],
                    'volume' => $detector['volume'],
                    'average_price' => $detector['average_price'],
                    'metrics_json' => $metricsJson,
                ],
            );
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $window
     * @param  array<string, mixed>  $meta
     */
    private function warnOnRangeMismatch(array $window, array $meta, string $path): void
    {
        $payloadFrom = $window['payload_from_date'] ?? null;
        $payloadTo = $window['payload_to_date'] ?? null;
        $fileFrom = $meta['from_date'] ?? null;
        $fileTo = $meta['to_date'] ?? null;

        if ($payloadFrom === null && $payloadTo === null) {
            return;
        }

        $mismatched = ($fileFrom !== null && $payloadFrom !== null && $payloadFrom !== $fileFrom)
            || ($fileTo !== null && $payloadTo !== null && $payloadTo !== $fileTo);

        if (! $mismatched) {
            return;
        }

        Log::warning('Broker summary range mismatch; the payload range was used.', [
            'symbol' => $window['symbol'],
            'payload_range' => $payloadFrom.'..'.$payloadTo,
            'filename_range' => $fileFrom.'..'.$fileTo,
            'file' => $path,
        ]);
    }

    /**
     * Import every broker summary JSON file in the archive.
     *
     * This walks the whole directory and is the recovery path:
     * `broker-summary:rebuild` uses it to reconstruct the canonical windows
     * from years of archived responses. It is deliberately not what the
     * weekly scheduler calls -- that job knows exactly which files it just
     * produced and imports those with importPaths(), instead of re-reading the
     * entire history every Friday.
     *
     * @return array{file_count:int,row_count:int,symbols:array<string,int>,imported:array<int,string>,skipped:array<int,string>}
     */
    public function importFromDisk(?string $disk = null, ?string $directory = null): array
    {
        $disk = $disk ?? (string) config('stockbit.save_disk', 'local');
        $directory = trim($directory ?? (string) config('stockbit.save_dir', 'broker_summary'), '/');

        try {
            $storage = Storage::disk($disk);
            $paths = array_filter(
                $storage->files($directory),
                static fn (string $path): bool => str_ends_with(strtolower($path), '.json')
            );
        } catch (\Throwable) {
            return [
                'file_count' => 0,
                'row_count' => 0,
                'symbols' => [],
                'imported' => [],
                'skipped' => [],
            ];
        }

        return $this->importPaths($paths, $disk);
    }

    /**
     * Import a known set of archive files.
     *
     * The scheduler writes a handful of JSON files and knows their paths, so
     * it should not pay to re-read and re-upsert the entire archive to get
     * them into the database -- that cost grows with every week the system
     * runs, and a Friday evening is the worst time to discover it.
     *
     * Re-importing the same file is a no-op in effect: a window is keyed on
     * (asset, from_date, to_date, transaction_type) and its entries are
     * replaced wholesale, so a retry converges rather than duplicating.
     *
     * @param  array<int, string>  $paths  Paths on $disk, as the scrape wrote them.
     * @return array{file_count:int,row_count:int,symbols:array<string,int>,imported:array<int,string>,skipped:array<int,string>}
     */
    public function importPaths(array $paths, ?string $disk = null): array
    {
        $disk = $disk ?? (string) config('stockbit.save_disk', 'local');

        $fileCount = 0;
        $rowCount = 0;
        $symbols = [];
        $imported = [];
        $skipped = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! str_ends_with(strtolower($path), '.json')) {
                continue;
            }

            $fileCount++;

            $outcome = $this->importFile($disk, $path);

            if (! $outcome['imported']) {
                $skipped[] = $path;

                continue;
            }

            $imported[] = $path;
            $rowCount += $outcome['rows'];

            if ($outcome['symbol'] !== null) {
                $symbols[$outcome['symbol']] = ($symbols[$outcome['symbol']] ?? 0) + $outcome['rows'];
            }
        }

        return [
            'file_count' => $fileCount,
            'row_count' => $rowCount,
            'symbols' => $symbols,
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * Import one archived response.
     *
     * @return array{imported:bool,rows:int,symbol:?string}
     */
    private function importFile(string $disk, string $path): array
    {
        $miss = ['imported' => false, 'rows' => 0, 'symbol' => null];

        $symbol = $this->symbolFromPath($path);

        if ($symbol === null) {
            return $miss;
        }

        try {
            $contents = Storage::disk($disk)->get($path);
        } catch (\Throwable $exception) {
            Log::warning('Broker summary file could not be read.', [
                'file' => $path,
                'message' => $exception->getMessage(),
            ]);

            return $miss;
        }

        $decoded = json_decode((string) $contents, true);

        if (! is_array($decoded)) {
            return $miss;
        }

        $meta = $this->metaFromPath($path);
        $transactionType = $meta['transaction_type'] ?? config('stockbit.defaults.transaction_type');

        $window = BrokerSummaryTransformer::toWindow(
            $symbol,
            $decoded,
            $meta['from_date'] ?? null,
            $meta['to_date'] ?? null,
            $transactionType,
        );

        $facts = BrokerSummaryTransformer::toFacts($symbol, $decoded, $transactionType);

        if ($facts === [] && $window === null) {
            return $miss;
        }

        $asset = Asset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);
        // firstOrCreate does not refresh DB-side defaults like
        // sync_broker_summary into the in-memory model on first insert,
        // so reload before reading the flag to avoid a false-negative
        // skip on freshly created assets.
        if ($asset->wasRecentlyCreated) {
            $asset->refresh();
        }
        if (! $asset->sync_broker_summary) {
            return $miss;
        }

        $timestamp = now();

        // The canonical record. Written first so the detector and, for a
        // genuine single day, the legacy projections can point at it.
        $windowModel = $window === null
            ? null
            : $this->storeWindow($asset, $window, $path, (string) $contents, $timestamp);

        // broker_summary_facts and broksums can only express one date, so
        // they are written solely for a genuine single-day window. Feeding
        // them a range aggregate stamped with its start date is the bug
        // this whole change exists to remove, and the strategy consumers
        // reading trade_date as a trading day would carry it onwards.
        $singleDay = $windowModel !== null && $windowModel->isSingleDay();

        if ($window !== null && ! $singleDay) {
            return ['imported' => true, 'rows' => count($window['entries']), 'symbol' => $symbol];
        }

        $factsPayload = [];
        $broksumPayload = [];

        foreach ($facts as $fact) {
            $factsPayload[] = [
                'asset_id' => $asset->id,
                'trade_date' => $fact['trade_date'],
                'broker_code' => $fact['broker_code'],
                'transaction_type' => $fact['transaction_type'],
                'broker_type' => $fact['broker_type'],
                'buy_lot' => $fact['buy_lot'],
                'buy_volume' => $fact['buy_volume'],
                'buy_value' => $fact['buy_value'],
                'buy_value_v' => $fact['buy_value_v'],
                'buy_avg_price' => $fact['buy_avg_price'],
                'sell_lot' => $fact['sell_lot'],
                'sell_volume' => $fact['sell_volume'],
                'sell_value' => $fact['sell_value'],
                'sell_value_v' => $fact['sell_value_v'],
                'sell_avg_price' => $fact['sell_avg_price'],
                'net_lot' => $fact['net_lot'],
                'net_volume' => $fact['net_volume'],
                'net_value' => $fact['net_value'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $broksumPayload[] = [
                'asset_id' => $asset->id,
                'date' => $fact['trade_date'],
                'broker' => $fact['broker_code'],
                'net_value' => $fact['net_value'],
                'buy_value' => $fact['buy_value'],
                'buy_avg_price' => $fact['buy_avg_price'],
                'sell_value' => $fact['sell_value'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        // A rejected row is reported by the driver as "at row 25" of a
        // 500-row chunk, which names neither the symbol nor the broker nor
        // the field. Check the payload first so the failure can say what is
        // actually wrong with which record.
        $this->guardMagnitudes($symbol, $factsPayload);

        foreach (array_chunk($factsPayload, 500) as $chunk) {
            BrokerSummaryFact::upsert(
                $chunk,
                ['asset_id', 'trade_date', 'broker_code', 'transaction_type'],
                [
                    'broker_type',
                    'buy_lot',
                    'buy_volume',
                    'buy_value',
                    'buy_value_v',
                    'buy_avg_price',
                    'sell_lot',
                    'sell_volume',
                    'sell_value',
                    'sell_value_v',
                    'sell_avg_price',
                    'net_lot',
                    'net_volume',
                    'net_value',
                    'updated_at',
                ]
            );
        }

        foreach (array_chunk($broksumPayload, 500) as $chunk) {
            Broksum::upsert(
                $chunk,
                ['asset_id', 'date', 'broker'],
                ['net_value', 'buy_value', 'buy_avg_price', 'sell_value', 'updated_at']
            );
        }

        // The detector is written by storeWindow(), tied to the window it
        // describes, so it is not repeated here.

        return ['imported' => true, 'rows' => count($factsPayload), 'symbol' => $symbol];
    }

    private function symbolFromPath(string $path): ?string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $parts = explode('_', $filename);
        $symbol = strtoupper((string) ($parts[0] ?? ''));

        return $symbol !== '' ? $symbol : null;
    }

    /**
     * @return array{symbol:?string,from_date:?string,to_date:?string,transaction_type:?string}
     */
    private function metaFromPath(string $path): array
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $parts = explode('_', $filename);

        $symbol = strtoupper((string) ($parts[0] ?? ''));
        $fromDate = $parts[1] ?? null;
        $toDate = $parts[2] ?? null;
        $transactionType = null;

        if (count($parts) > 3) {
            $transactionType = implode('_', array_slice($parts, 3));
        }

        return [
            'symbol' => $symbol !== '' ? $symbol : null,
            'from_date' => is_string($fromDate) && $fromDate !== '' ? $fromDate : null,
            'to_date' => is_string($toDate) && $toDate !== '' ? $toDate : null,
            'transaction_type' => is_string($transactionType) && $transactionType !== '' ? $transactionType : null,
        ];
    }
}

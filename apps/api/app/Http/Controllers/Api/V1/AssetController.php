<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AtrResource;
use App\Http\Resources\PriceResource;
use App\Models\Asset;
use App\Models\Metric;
use App\Services\Analysis\AssetMetricProjector;
use App\Services\AssetMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssetController extends ApiController
{
    /**
     * Display a listing of assets.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $query = Asset::query();

        if ($this->include('prices')) {
            $query->with('prices');
        }

        if ($this->include('latest_price')) {
            $query->with('latestPriceRecord');
        }

        return ApiResponse::success(AssetResource::collection($query->get()));
    }

    /**
     * The cached structural metrics table, in canonical structural rank order.
     *
     * The ordering below is the SQL form of
     * AssetTechnicalSnapshot::structuralSortKey(). It used to rank on PBAS in
     * the second position where the CLI ranked on ROC13, so the same asset
     * carried two different "Rank" values depending on which surface you
     * opened. PBAS is a broker-accumulation signal and is scored in the
     * execution pipeline; it has no place in a structural ordering.
     */
    public function metricsIndex()
    {
        $metrics = Metric::orderByDesc('sort_uptrend')
            ->orderByDesc('sort_roc13')
            ->orderByDesc('sort_close_vs_high55')
            ->orderByDesc('sort_close_vs_high20')
            ->orderByDesc('sort_vol_vs_avg20')
            ->orderBy('symbol')
            ->get();

        $rankedRows = [];

        foreach ($metrics as $index => $metric) {
            $rankedRows[] = [
                // Kept as `rank` for existing consumers; `structural_rank` is
                // the name the new surfaces use for the same number.
                'rank' => $index + 1,
                'structural_rank' => $index + 1,
                'asset_id' => $metric->asset_id,
                'symbol' => $metric->symbol,
                'name' => $metric->name,
                'close' => $metric->close,
                'ma50' => $metric->ma50,
                'ma100' => $metric->ma100,
                'high20' => $metric->high20,
                'high55' => $metric->high55,
                'atr14' => $metric->atr14,
                'roc13' => $metric->roc13,
                'avg_vol20' => $metric->avg_vol20,
                'vol_vs_avg20' => $metric->vol_vs_avg20,
                'close_vs_high20' => $metric->close_vs_high20,
                'close_vs_high55' => $metric->close_vs_high55,
                'uptrend' => $metric->uptrend,
                'bars' => $metric->bars,
                'pbas' => $metric->pbas,
                'bavg' => $metric->bavg,
            ];
        }

        return ApiResponse::success([
            'metrics' => $rankedRows,
        ]);
    }

    public function metricForAsset(Asset $asset)
    {
        $metric = Metric::where('asset_id', $asset->id)->first();

        if (! $metric) {
            return ApiResponse::error('Metrics not found for this asset.', 404);
        }

        return ApiResponse::success([
            'metric' => [
                'rank' => null,
                'asset_id' => $metric->asset_id,
                'symbol' => $metric->symbol,
                'name' => $metric->name,
                'close' => $metric->close,
                'ma50' => $metric->ma50,
                'ma100' => $metric->ma100,
                'high20' => $metric->high20,
                'high55' => $metric->high55,
                'atr14' => $metric->atr14,
                'roc13' => $metric->roc13,
                'avg_vol20' => $metric->avg_vol20,
                'vol_vs_avg20' => $metric->vol_vs_avg20,
                'close_vs_high20' => $metric->close_vs_high20,
                'close_vs_high55' => $metric->close_vs_high55,
                'uptrend' => $metric->uptrend,
                'bars' => $metric->bars,
                'pbas' => $metric->pbas,
                'bavg' => $metric->bavg,
            ],
        ]);
    }

    public function updateSyncSettings(Request $request)
    {
        $payload = $request->validate([
            'assets' => 'required|array',
            'assets.*.id' => 'required|integer|exists:assets,id',
            'assets.*.sync_price' => 'required|boolean',
            'assets.*.sync_profile' => 'required|boolean',
            'assets.*.sync_broker_summary' => 'required|boolean',
        ]);

        $assetsPayload = $payload['assets'] ?? [];
        $updatedAssets = [];

        foreach ($assetsPayload as $row) {
            $asset = Asset::find($row['id']);
            if (! $asset) {
                continue;
            }

            $asset->update([
                'sync_price' => (bool) $row['sync_price'],
                'sync_profile' => (bool) $row['sync_profile'],
                'sync_broker_summary' => (bool) $row['sync_broker_summary'],
            ]);

            $updatedAssets[] = AssetResource::make($asset->fresh());
        }

        return ApiResponse::success(
            ['assets' => $updatedAssets],
            'Asset sync settings updated.'
        );
    }

    /**
     * Recalculate the structural metrics cache.
     *
     * A thin caller: every figure and the ordering come from
     * AssetMetricProjector, the same path `php artisan asset:metrics --persist`
     * takes. This method used to carry its own copy of the moving-average,
     * high, ATR, ROC and volume-ratio formulas, which had already drifted from
     * the command's copy.
     */
    public function updateMetrics(AssetMetricProjector $projector)
    {
        $updated = 0;
        $removed = 0;

        Asset::query()->orderBy('id')->chunkById(200, function ($assets) use ($projector, &$updated, &$removed) {
            $rows = $projector->project($assets);

            $updated += $projector->persist($rows);
            $removed += $projector->forgetMissing(
                $assets->map(static fn (Asset $asset): int => (int) $asset->id)->all(),
                $rows,
            );
        });

        return ApiResponse::success(
            [
                'updated' => $updated,
                'removed' => $removed,
            ],
            'Metrics recalculated successfully'
        );
    }

    /**
     * Store a newly created asset in storage.
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:10|unique:assets,symbol',
            'name' => 'required|string',
            'lot_size' => 'nullable|numeric',
            'tick_size' => 'nullable|numeric',
            'sync_price' => 'sometimes|boolean',
            'sync_profile' => 'sometimes|boolean',
            'sync_broker_summary' => 'sometimes|boolean',
        ]);

        foreach (['sync_price', 'sync_profile', 'sync_broker_summary'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $asset = Asset::create($data);

        return ApiResponse::success(
            AssetResource::make($asset),
            'Asset created successfully',
            201
        );
    }

    /**
     * Display the specified asset.
     *
     * @return JsonResponse
     */
    public function show(Asset $asset)
    {
        if ($this->include('prices')) {
            $asset->load('prices');
        }

        if ($this->include('latest_price')) {
            $asset->load('latestPriceRecord');
        }

        return ApiResponse::success(AssetResource::make($asset));
    }

    /**
     * Update the specified asset in storage.
     *
     * @return JsonResponse
     */
    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'symbol' => 'sometimes|required|string|max:10|unique:assets,symbol,'.$asset->id,
            'name' => 'sometimes|required|string',
            'lot_size' => 'nullable|numeric',
            'tick_size' => 'nullable|numeric',
            'sync_price' => 'sometimes|boolean',
            'sync_profile' => 'sometimes|boolean',
            'sync_broker_summary' => 'sometimes|boolean',
        ]);

        foreach (['sync_price', 'sync_profile', 'sync_broker_summary'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $asset->update($data);

        if ($this->include('prices')) {
            $asset->load('prices');
        }

        if ($this->include('latest_price')) {
            $asset->load('latestPriceRecord');
        }

        return ApiResponse::success(
            AssetResource::make($asset),
            'Asset updated successfully'
        );
    }

    /**
     * Remove the specified asset from storage.
     *
     * @return JsonResponse
     */
    public function destroy(Asset $asset)
    {
        $asset->delete();

        return ApiResponse::success(null, 'Asset deleted successfully');
    }

    /**
     * Retrieve the latest OHLCV price for the asset.
     *
     * @return JsonResponse
     */
    public function latestPrice(Asset $asset)
    {
        $price = $asset->latestPrice();

        if (! $price) {
            return ApiResponse::error('Price data not found', 404);
        }

        return ApiResponse::success(PriceResource::make($price));
    }

    /**
     * Retrieve the latest OHLCV price for the given asset symbol.
     *
     * @return JsonResponse
     */
    public function latestPriceBySymbol(string $symbol)
    {
        $validator = Validator::make([
            'symbol' => $symbol,
        ], [
            'symbol' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error($validator->errors()->first(), 422);
        }

        $normalizedSymbol = strtolower($symbol);

        $asset = Asset::whereRaw('LOWER(symbol) = ?', [$normalizedSymbol])->first();

        if (! $asset) {
            return ApiResponse::error('Asset not found', 404);
        }

        $price = $asset->latestPrice();

        if (! $price) {
            return ApiResponse::error('Price data not found', 404);
        }

        return ApiResponse::success(PriceResource::make($price));
    }

    /**
     * Retrieve the latest OHLCV prices for all assets.
     *
     * @return JsonResponse
     */
    public function latestPrices()
    {
        $assets = Asset::with('latestPriceRecord')->get();

        $prices = $assets->map(function ($asset) {
            $price = $asset->latestPriceRecord;
            if ($price) {
                $price->setRelation('asset', $asset);
            }

            return $price;
        })->filter()->values();

        return ApiResponse::success(PriceResource::collection($prices));
    }

    public function atr(Request $request, Asset $asset)
    {
        return $this->atrResponse($request, $asset);
    }

    public function atrBySymbol(Request $request, string $symbol)
    {
        $validator = Validator::make([
            'symbol' => $symbol,
        ], [
            'symbol' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error($validator->errors()->first(), 422);
        }

        $normalizedSymbol = strtolower($symbol);

        $asset = Asset::whereRaw('LOWER(symbol) = ?', [$normalizedSymbol])->first();

        if (! $asset) {
            return ApiResponse::error('Asset not found', 404);
        }

        return $this->atrResponse($request, $asset);
    }

    /**
     * Compute simple metrics for the asset.
     *
     * @return JsonResponse
     */
    public function metrics(Asset $asset, AssetMetrics $metrics)
    {
        return ApiResponse::success($metrics->forAsset($asset));
    }

    private function atrResponse(Request $request, Asset $asset)
    {
        $period = (int) $request->query('period', 14);
        if ($period < 1) {
            return ApiResponse::error('Period must be at least 1.', 422);
        }

        $interval = strtolower((string) $request->query('interval', 'daily'));
        if (! in_array($interval, ['daily', 'weekly'], true)) {
            return ApiResponse::error('Interval must be one of daily or weekly.', 422);
        }

        $prices = $asset->prices()->orderBy('date')->get();

        if ($prices->isEmpty()) {
            return ApiResponse::error('Price data not found', 404);
        }

        $bars = $interval === 'weekly'
            ? AssetMetrics::buildWeeklyBars($prices)
            : AssetMetrics::buildDailyBars($prices);

        if ($bars === [] || count($bars) < $period) {
            return ApiResponse::error('Insufficient price history to compute ATR.', 422);
        }

        $metrics = new AssetMetrics($bars);
        $atrMode = $interval === 'weekly' ? 'weekly' : 'standard';
        $atr = $metrics->atr($period, $atrMode);

        $lastBar = $bars[array_key_last($bars)] ?? null;

        return ApiResponse::success(AtrResource::make([
            'asset' => $asset,
            'interval' => $interval,
            'period' => $period,
            'atr' => $atr,
            'last_bar' => $lastBar,
            'bar_count' => count($bars),
        ]));
    }
}

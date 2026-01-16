<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Asset;
use App\Models\Metric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AtrResource;
use App\Http\Resources\PriceResource;
use App\Services\AssetMetrics;
use App\Models\BandarDetectorSummary;
use Illuminate\Support\Facades\DB;

class AssetController extends ApiController
{
    /**
     * Display a listing of assets.
     *
     * @return \Illuminate\Http\JsonResponse
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

    public function testReturn(){
        return ApiResponse::success("Test Return");
    }

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
                'rank' => $index + 1,
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

        if (!$metric) {
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
            if (!$asset) {
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

    public function updateMetrics()
    {
        $updates = 0;

        Asset::with(['prices' => function ($query) {
            $query->orderBy('date');
        }])->chunkById(50, function ($assets) use (&$updates) {
            foreach ($assets as $asset) {
                $prices = $asset->prices;

                if ($prices->isEmpty()) {
                    Metric::where('asset_id', $asset->id)->delete();
                    continue;
                }

                $bars = AssetMetrics::buildDailyBars($prices);

                if ($bars === []) {
                    Metric::where('asset_id', $asset->id)->delete();
                    continue;
                }

                $metrics = new AssetMetrics($bars);

                $close = round($metrics->lastClose(), 2);
                $ma50 = round($metrics->movingAverage(50), 0);
                $ma100 = round($metrics->movingAverage(100), 0);
                $high20 = round($metrics->periodHigh(20), 0);
                $high55 = round($metrics->periodHigh(55), 0);
                $atr14 = round($metrics->atr(14), 0);

                $roc13 = null;
                $rocLookback = 13 * 5;
                if ($metrics->barCount() > $rocLookback) {
                    $roc13 = round($metrics->rocWeeks(13), 2);
                }

                $avgVol20 = round($metrics->averageVolume(20), 0);
                $lastVolume = round($metrics->lastVolume(), 0);
                $volVsAvg20 = $avgVol20 > 0 ? round($lastVolume / $avgVol20, 2) : null;

                $closeVsHigh20 = $high20 > 0 ? round($close / $high20, 2) : null;
                $closeVsHigh55 = $high55 > 0 ? round($close / $high55, 2) : null;
                $pbas = DB::table('features_daily')
                    ->where('symbol', $asset->symbol)
                    ->orderByDesc('date')
                    ->value('pbas');
                $pbas = $pbas === null ? null : (int) $pbas;
                $latestDate = $prices->last()?->date;
                $bavg = null;
                if ($latestDate) {
                    $bavg = BandarDetectorSummary::query()
                        ->where('asset_id', $asset->id)
                        ->whereNotNull('average_price')
                        ->whereDate('from_date', '<=', $latestDate->toDateString())
                        ->whereDate('to_date', '>=', $latestDate->toDateString())
                        ->orderByDesc('from_date')
                        ->value('average_price');
                    $bavg = $bavg === null ? null : (float) $bavg;
                }

                Metric::updateOrCreate(
                    ['asset_id' => $asset->id],
                    [
                        'symbol' => $asset->symbol,
                        'name' => $asset->name,
                        'close' => $close,
                        'ma50' => $ma50,
                        'ma100' => $ma100,
                        'high20' => $high20,
                        'high55' => $high55,
                        'atr14' => $atr14,
                        'roc13' => $roc13 === null ? null : (float) $roc13,
                        'avg_vol20' => $avgVol20,
                        'vol_vs_avg20' => $volVsAvg20,
                        'close_vs_high20' => $closeVsHigh20,
                        'close_vs_high55' => $closeVsHigh55,
                        'uptrend' => $metrics->isUptrend(),
                        'bars' => $metrics->barCount(),
                        'pbas' => $pbas,
                        'bavg' => $bavg,
                        'sort_uptrend' => $metrics->isUptrend() ? 1 : 0,
                        'sort_roc13' => (float) ($roc13 ?? 0.0),
                        'sort_close_vs_high55' => (float) ($closeVsHigh55 ?? 0.0),
                        'sort_close_vs_high20' => (float) ($closeVsHigh20 ?? 0.0),
                        'sort_vol_vs_avg20' => (float) ($volVsAvg20 ?? 0.0),
                    ]
                );

                $updates++;
            }
        });

        return ApiResponse::success(
            [
                'updated' => $updates,
            ],
            'Metrics recalculated successfully'
        );
    }

    /**
     * Store a newly created asset in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
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
     * @param  \App\Models\Asset  $asset
     * @return \Illuminate\Http\JsonResponse
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
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Asset  $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'symbol' => 'sometimes|required|string|max:10|unique:assets,symbol,' . $asset->id,
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
     * @param  \App\Models\Asset  $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Asset $asset)
    {
        $asset->delete();

        return ApiResponse::success(null, 'Asset deleted successfully');
    }

    /**
     * Retrieve the latest OHLCV price for the asset.
     *
     * @param  \App\Models\Asset  $asset
     * @return \Illuminate\Http\JsonResponse
     */
    public function latestPrice(Asset $asset)
    {
        $price = $asset->latestPrice();

        if (!$price) {
            return ApiResponse::error('Price data not found', 404);
        }

        return ApiResponse::success(PriceResource::make($price));
    }

    /**
     * Retrieve the latest OHLCV price for the given asset symbol.
     *
     * @param  string  $symbol
     * @return \Illuminate\Http\JsonResponse
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

        if (!$asset) {
            return ApiResponse::error('Asset not found', 404);
        }

        $price = $asset->latestPrice();

        if (!$price) {
            return ApiResponse::error('Price data not found', 404);
        }

        return ApiResponse::success(PriceResource::make($price));
    }

    /**
     * Retrieve the latest OHLCV prices for all assets.
     *
     * @return \Illuminate\Http\JsonResponse
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

        if (!$asset) {
            return ApiResponse::error('Asset not found', 404);
        }

        return $this->atrResponse($request, $asset);
    }

    /**
     * Compute simple metrics for the asset.
     *
     * @param  \App\Models\Asset  $asset
     * @param  \App\Services\AssetMetrics  $metrics
     * @return \Illuminate\Http\JsonResponse
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
        if (!in_array($interval, ['daily', 'weekly'], true)) {
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

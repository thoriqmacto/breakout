<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AtrResource;
use App\Http\Resources\PriceResource;
use App\Services\AssetMetrics;

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
        ]);

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
        ]);

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
        $atr = $metrics->atr($period);

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

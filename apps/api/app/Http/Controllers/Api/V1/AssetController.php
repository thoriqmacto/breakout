<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Asset;
use Illuminate\Http\Request;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\AssetResource;
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
}

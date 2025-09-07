<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Collection;

class AssetMetrics
{
    /**
     * Calculate metrics for the given asset.
     *
     * @param  \App\Models\Asset  $asset
     * @return array<string, float|int|null>
     */
    public function forAsset(Asset $asset): array
    {
        // Retrieve ordered price data for the asset
        $prices = $asset->prices()->orderBy('date')->get();

        if ($prices->isEmpty()) {
            return [
                'average_price' => null,
                'total_volume' => 0,
            ];
        }

        return [
            'average_price' => $prices->avg('close'),
            'total_volume' => (int) $prices->sum('volume'),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Asset;

class AssetList
{
    /**
     * Retrieve the list of asset symbols stored in the database.
     *
     * @return array<int, string>
     */
    public static function symbols(): array
    {
        return Asset::query()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

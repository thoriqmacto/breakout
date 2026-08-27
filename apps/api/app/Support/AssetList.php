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
    public static function symbols(bool $onlyPriceSync = false): array
    {
        $query = Asset::query();

        if ($onlyPriceSync) {
            $query->where('sync_price', true);
        }

        return $query
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Symbols whose broker summary should be fetched.
     *
     * The importer already refuses to store a window for an asset with
     * sync_broker_summary disabled, so scraping one spends an API call on a
     * response that is then thrown away. This is the same setting applied one
     * step earlier.
     *
     * @return array<int, string>
     */
    public static function brokerSummarySymbols(): array
    {
        return Asset::query()
            ->where('sync_broker_summary', true)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

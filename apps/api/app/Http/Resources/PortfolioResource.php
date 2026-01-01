<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_ccy' => $this->base_ccy,
            'remarks' => $this->remarks,
            'year' => $this->year,
            'positions_count' => $this->when(
                isset($this->positions_count) || $this->relationLoaded('positions'),
                fn () => $this->relationLoaded('positions')
                    ? $this->positions->filter(fn ($position) => $this->year === null || $position->executed_at?->year === $this->year)->count()
                    : $this->positions_count
            ),
            'positions' => PositionResource::collection($this->whenLoaded('positions')),
            'asset_summaries' => $this->when(
                $this->relationLoaded('positions'),
                fn () => $this->buildAssetSummaries()
            ),
        ];
    }

    /**
     * Build unique asset summaries for the portfolio positions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildAssetSummaries(): array
    {
        $year = $this->year;

        $positions = $this->positions->filter(function ($position) use ($year) {
            if (! $year) {
                return true;
            }

            return $position->executed_at?->year === $year;
        });

        return $positions->groupBy('asset_id')->map(function ($assetPositions) {
            $asset = $assetPositions->first()?->asset;
            $entryTrades = $assetPositions->where('side', 'entry');
            $exitTrades = $assetPositions->where('side', 'exit');

            $entryShares = $entryTrades->sum('qty_shares');
            $entryValue = $entryTrades->sum(fn ($trade) => $trade->qty_shares * $trade->avg_price);
            $exitShares = $exitTrades->sum('qty_shares');
            $exitValue = $exitTrades->sum(fn ($trade) => $trade->qty_shares * $trade->avg_price);
            $plValue = $exitValue - $entryValue;
            $plFlag = $plValue > 0 ? 'profit' : ($plValue < 0 ? 'loss' : 'breakeven');

            return [
                'asset' => $asset ? [
                    'id' => $asset->id,
                    'symbol' => $asset->symbol,
                    'name' => $asset->name,
                ] : null,
                'entry' => [
                    'min_price' => $entryTrades->min('price'),
                    'max_price' => $entryTrades->max('price'),
                    'total_shares' => $entryShares,
                    'average_price' => $entryShares > 0 ? $entryValue / $entryShares : null,
                    'value' => $entryValue,
                ],
                'exit' => [
                    'min_price' => $exitTrades->min('price'),
                    'max_price' => $exitTrades->max('price'),
                    'total_shares' => $exitShares,
                    'average_price' => $exitShares > 0 ? $exitValue / $exitShares : null,
                    'value' => $exitValue,
                    'pl_percent' => $entryValue > 0 ? ($plValue / $entryValue) * 100 : null,
                    'pl_value' => $plValue,
                    'pl_flag' => $plFlag,
                ],
            ];
        })->values()->all();
    }
}

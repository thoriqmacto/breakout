<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'portfolio_id' => $this->portfolio_id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', function () {
                return [
                    'id' => $this->asset->id,
                    'symbol' => $this->asset->symbol,
                    'name' => $this->asset->name,
                ];
            }),
            'side' => $this->side,
            'qty_shares' => $this->qty_shares,
            'price' => $this->price,
            'fee_rate' => $this->fee_rate,
            'fee_value' => $this->fee_value,
            'avg_price' => $this->avg_price,
            // Unchanged date string so existing callers keep working, plus
            // the full timestamp: several fills can share a day and the order
            // between them is what the cost basis depends on.
            'executed_at' => $this->executed_at?->toDateString(),
            'executed_at_iso' => $this->executed_at?->toIso8601String(),
            'source' => $this->source,
            'external_id' => $this->external_id,
            'value' => $this->qty_shares * $this->avg_price,
        ];
    }
}

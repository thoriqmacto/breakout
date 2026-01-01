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
            'executed_at' => $this->executed_at?->toDateString(),
            'value' => $this->qty_shares * $this->avg_price,
        ];
    }
}

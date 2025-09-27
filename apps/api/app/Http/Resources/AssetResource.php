<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'lot_size' => $this->lot_size,
            'tick_size' => $this->tick_size,
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
            'latest_price' => PriceResource::make($this->whenLoaded('latestPriceRecord')),
        ];
    }
}

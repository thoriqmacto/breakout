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
            'sector' => $this->sector,
            'float' => $this->float,
            'ipo_price' => $this->ipo_price,
            'ipo_date' => optional($this->ipo_date)->toDateString(),
            'marketcap' => $this->marketcap,
            'background' => $this->background,
            'history' => $this->history,
            'address' => $this->address,
            'profile_synced_at' => optional($this->profile_synced_at)->toIso8601String(),
            'sync_price' => (bool) $this->sync_price,
            'sync_profile' => (bool) $this->sync_profile,
            'sync_broker_summary' => (bool) $this->sync_broker_summary,
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
            'latest_price' => PriceResource::make($this->whenLoaded('latestPriceRecord')),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BroksumResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $date = $this->date;

        return [
            'asset_id' => $this->asset_id,
            'symbol' => $this->whenLoaded('asset', fn () => $this->asset->symbol, null),
            'asset_name' => $this->whenLoaded('asset', fn () => $this->asset->name, null),
            'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date,
            'broker' => $this->broker,
            'net_value' => (float) $this->net_value,
            'buy_value' => (float) $this->buy_value,
            'sell_value' => (float) $this->sell_value,
        ];
    }
}

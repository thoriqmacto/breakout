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
            'positions_count' => $this->when(isset($this->positions_count), $this->positions_count),
            'positions' => PositionResource::collection($this->whenLoaded('positions')),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One broker's position within a window.
 *
 * net_* and gross_* stay separate: the net figures are Stockbit's own
 * range-level classification, the gross figures are traded activity, and
 * dividing one by the other is what reproduces the source's average price.
 */
class BrokerSummaryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'broker_code' => $this->broker_code,
            'side' => $this->side,
            'broker_type' => $this->broker_type,
            'frequency' => $this->frequency,
            // netbs_date, for auditing. Not a trading date: in a ranged
            // response it is the range start repeated on every row.
            'source_date' => $this->source_date?->toDateString(),
            'net_lot' => $this->net_lot,
            'net_value' => $this->net_value,
            'gross_volume' => $this->gross_volume,
            'gross_value' => $this->gross_value,
            'average_price' => $this->average_price,
            'window' => $this->whenLoaded('window', fn () => [
                'id' => $this->window->id,
                'from_date' => $this->window->from_date?->toDateString(),
                'to_date' => $this->window->to_date?->toDateString(),
                'transaction_type' => $this->window->transaction_type,
                'is_single_day' => $this->window->isSingleDay(),
                'symbol' => $this->window->relationLoaded('asset') ? $this->window->asset?->symbol : null,
            ]),
        ];
    }
}

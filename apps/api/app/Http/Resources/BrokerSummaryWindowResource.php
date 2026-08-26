<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A broker-summary window: one Stockbit aggregate over from_date..to_date.
 *
 * `is_single_day` is stated explicitly so the client never has to infer from
 * a source date whether it is looking at a day or a quarter.
 */
class BrokerSummaryWindowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->whenLoaded('asset', fn () => $this->asset->symbol),
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'transaction_type' => $this->transaction_type,
            'is_single_day' => $this->isSingleDay(),
            'market_board' => $this->market_board,
            'investor_type' => $this->investor_type,
            'imported_at' => $this->imported_at?->toIso8601String(),

            // Both the returned and the true counts, so the UI can say
            // "Showing 25 of 42" rather than implying the list is complete.
            'coverage' => [
                'returned_buyer_count' => $this->returned_buyer_count,
                'total_buyer' => $this->total_buyer,
                'buyers_truncated' => $this->buyersTruncated(),
                'returned_seller_count' => $this->returned_seller_count,
                'total_seller' => $this->total_seller,
                'sellers_truncated' => $this->sellersTruncated(),
            ],

            'buyers' => BrokerSummaryEntryResource::collection(
                $this->whenLoaded('buyers')
            ),
            'sellers' => BrokerSummaryEntryResource::collection(
                $this->whenLoaded('sellers')
            ),

            'bandar_detector' => $this->whenLoaded(
                'bandarDetectorSummary',
                fn () => $this->bandarDetectorSummary === null ? null : [
                    'broker_accdist' => $this->bandarDetectorSummary->broker_accdist,
                    'number_broker_buysell' => $this->bandarDetectorSummary->number_broker_buysell,
                    'total_buyer' => $this->bandarDetectorSummary->total_buyer,
                    'total_seller' => $this->bandarDetectorSummary->total_seller,
                    'value' => $this->bandarDetectorSummary->value,
                    'volume' => $this->bandarDetectorSummary->volume,
                    'average_price' => $this->bandarDetectorSummary->average_price,
                    // Passed through whole, including groups added after this
                    // was written.
                    'metrics' => $this->bandarDetectorSummary->metrics_json,
                ],
            ),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/**
 * Resource representation for Average True Range responses.
 */
class AtrResource extends JsonResource
{
    /**
     * Ensure Laravel doesn't wrap the response.
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        /** @var array{asset: Asset, interval: string, period: int, atr: float, last_bar: ?array, bar_count: int} $data */
        $data = $this->resource;

        $asset = $data['asset'];
        if (! $asset instanceof Asset) {
            throw new InvalidArgumentException('AtrResource expects an Asset instance.');
        }
        $lastBar = $data['last_bar'] ?? null;

        return [
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'interval' => $data['interval'],
            'period' => $data['period'],
            'atr' => $data['atr'],
            'last_close' => $lastBar['close'] ?? null,
            'last_date' => $lastBar['date'] ?? null,
            'bar_count' => $data['bar_count'],
        ];
    }
}

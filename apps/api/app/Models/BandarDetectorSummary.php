<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandarDetectorSummary extends Model
{
    protected $table = 'bandar_detector_summaries';

    protected $fillable = [
        'asset_id',
        'from_date',
        'to_date',
        'transaction_type',
        'broker_accdist',
        'number_broker_buysell',
        'total_buyer',
        'total_seller',
        'value',
        'volume',
        'average_price',
        'metrics_json',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'number_broker_buysell' => 'int',
        'total_buyer' => 'int',
        'total_seller' => 'int',
        'value' => 'float',
        'volume' => 'int',
        'average_price' => 'float',
        'metrics_json' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

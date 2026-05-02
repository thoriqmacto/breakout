<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerAccumulationWindow extends Model
{
    protected $table = 'broker_accumulation_windows';

    protected $guarded = ['id'];

    protected $casts = [
        'asset_id' => 'integer',
        'end_date' => 'date:Y-m-d',
        'window_days' => 'integer',
        'avg_net_norm' => 'float',
        'top3_net_norm' => 'float',
        'top5_net_norm' => 'float',
        'foreign_net_norm' => 'float',
        'local_net_norm' => 'float',
        'accdist_score' => 'integer',
        'broker_count' => 'integer',
        'value' => 'float',
        'volume' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

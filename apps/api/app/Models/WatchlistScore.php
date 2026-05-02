<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistScore extends Model
{
    protected $table = 'watchlist_scores';

    protected $guarded = ['id'];

    protected $casts = [
        'scan_date' => 'date:Y-m-d',
        'asset_id' => 'integer',
        'close' => 'float',
        'net_value' => 'float',
        'vol_ratio_20' => 'float',
        'breakout20' => 'boolean',
        'score_total' => 'float',
        'score_bas' => 'float',
        'score_bcs' => 'float',
        'lf_pass' => 'boolean',
        'rrf_pass' => 'boolean',
        'invalidation_level' => 'float',
        'take_profit' => 'float',
        'risk_reward' => 'float',
        'top_brokers' => 'array',
        'reasons' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

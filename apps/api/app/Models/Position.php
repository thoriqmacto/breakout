<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'portfolio_id' => 'integer',
        'asset_id' => 'integer',
        'qty_shares' => 'float',
        'price' => 'float',
        'fee_rate' => 'float',
        'fee_value' => 'float',
        'avg_price' => 'float',
        'executed_at' => 'date',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

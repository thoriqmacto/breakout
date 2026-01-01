<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broksum extends Model
{
    protected $table = 'broksums';

    protected $fillable = [
        'asset_id',
        'date',
        'broker',
        'net_value',
        'buy_value',
        'sell_value',
    ];

    protected $casts = [
        'date' => 'date',
        'net_value' => 'float',
        'buy_value' => 'float',
        'sell_value' => 'float',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'symbol',
        'name',
        'close',
        'ma50',
        'ma100',
        'high20',
        'high55',
        'atr14',
        'roc13',
        'avg_vol20',
        'vol_vs_avg20',
        'close_vs_high20',
        'close_vs_high55',
        'uptrend',
        'bars',
        'sort_uptrend',
        'sort_roc13',
        'sort_close_vs_high55',
        'sort_close_vs_high20',
        'sort_vol_vs_avg20',
    ];

    protected $casts = [
        'uptrend' => 'boolean',
        'close' => 'float',
        'ma50' => 'float',
        'ma100' => 'float',
        'high20' => 'float',
        'high55' => 'float',
        'atr14' => 'float',
        'roc13' => 'float',
        'avg_vol20' => 'float',
        'vol_vs_avg20' => 'float',
        'close_vs_high20' => 'float',
        'close_vs_high55' => 'float',
        'sort_uptrend' => 'integer',
        'sort_roc13' => 'float',
        'sort_close_vs_high55' => 'float',
        'sort_close_vs_high20' => 'float',
        'sort_vol_vs_avg20' => 'float',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}

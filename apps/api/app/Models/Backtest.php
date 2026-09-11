<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Backtest extends Model
{
    protected $table = 'backtests';

    protected $primaryKey = 'run_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'created_at',
        'asset_id',
        'symbol',
        'strategy',
        'source',
        'params_json',
        'stats_json',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'params_json' => 'array',
        'stats_json' => 'array',
    ];

    /**
     * @return HasMany<BacktestTrade>
     */
    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class, 'run_id', 'run_id');
    }

    /**
     * @return BelongsTo<Asset, Backtest>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

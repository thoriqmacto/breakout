<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
}

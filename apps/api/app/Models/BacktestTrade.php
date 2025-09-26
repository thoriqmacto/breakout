<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestTrade extends Model
{
    protected $table = 'backtest_trades';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'asset_id',
        'entry_date',
        'entry_px',
        'exit_date',
        'exit_px',
        'units',
        'pnl',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'exit_date' => 'date',
    ];

    /**
     * @return BelongsTo<Asset, BacktestTrade>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Backtest, BacktestTrade>
     */
    public function backtest(): BelongsTo
    {
        return $this->belongsTo(Backtest::class, 'run_id', 'run_id');
    }
}

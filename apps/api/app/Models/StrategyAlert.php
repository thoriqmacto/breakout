<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing request to be told when a strategy fires on an asset.
 *
 * The evaluation uses the same strategy classes the backtester walks history
 * with, so an alert and the backtest that justified it cannot disagree about
 * what the strategy does.
 */
class StrategyAlert extends Model
{
    public const SIGNAL_BUY = 'buy';

    public const SIGNAL_SELL = 'sell';

    protected $fillable = [
        'user_id',
        'asset_id',
        'symbol',
        'strategy',
        'signal',
        'enabled',
        'last_triggered_on',
        'last_signal',
        'last_evaluated_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_triggered_on' => 'date',
        'last_evaluated_at' => 'datetime',
    ];

    /**
     * The key an AutomationAlert is raised under for this subscription.
     *
     * Per subscription rather than per asset: two strategies firing on the
     * same symbol are two things to look at, and one key would let the second
     * overwrite the first.
     */
    public function alertKey(): string
    {
        return sprintf('strategy-%d', $this->id);
    }

    /**
     * @return BelongsTo<Asset, StrategyAlert>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, StrategyAlert>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

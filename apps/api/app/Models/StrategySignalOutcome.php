<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One historical signal and what happened after it.
 *
 * Written by `strategy:evaluate-outcomes`, read by the probability service.
 * Never written from a request: forward outcomes are expensive to simulate
 * and must be reproducible, which a page load can guarantee neither of.
 */
class StrategySignalOutcome extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'asset_id' => 'integer',
        'signal_date' => 'date',
        'entry_date' => 'date',
        'broker_persistence_ratio' => 'float',
        'positive_broker_windows' => 'integer',
        'available_broker_windows' => 'integer',
        'broker_acceleration' => 'float',
        'execution_score' => 'float',
        'breakout20' => 'boolean',
        'breakout55' => 'boolean',
        'vol_ratio_20' => 'float',
        'close_pos' => 'float',
        'atr14' => 'float',
        'trigger_price' => 'float',
        'entry_price' => 'float',
        'initial_stop' => 'float',
        'initial_risk_pct' => 'float',
        'hit_5pct' => 'boolean',
        'days_to_5pct' => 'integer',
        'hit_initial_stop' => 'boolean',
        'days_to_initial_stop' => 'integer',
        'hit_stop_before_5pct' => 'boolean',
        'reached_5pct_before_stop' => 'boolean',
        'trailing_activated' => 'boolean',
        'trailing_activated_at' => 'date',
        'exit_date' => 'date',
        'exit_price' => 'float',
        'max_gain_before_exit_pct' => 'float',
        'gross_return_pct' => 'float',
        'net_return_pct' => 'float',
        'hold_sessions' => 'integer',
        'resolved' => 'boolean',
        'context' => 'array',
    ];

    /**
     * Normalise the signal date to `Y-m-d` on write.
     *
     * `signal_date` is part of the unique key, and `updateOrCreate` binds the
     * *raw* value into its lookup while the `date` cast writes
     * "Y-m-d 00:00:00". The two spellings never match, so every re-run
     * inserted a duplicate rather than updating -- the same defect that split
     * `trading_days` across two keys. A mutator takes precedence over the
     * cast on write, which puts both halves on the short form.
     */
    public function setSignalDateAttribute(mixed $value): void
    {
        $this->attributes['signal_date'] = $value === null
            ? null
            : Carbon::parse((string) (is_object($value) ? $value->format('Y-m-d') : $value))->toDateString();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

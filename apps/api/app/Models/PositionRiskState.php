<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The profit lifecycle of one holding.
 *
 * Keyed on (portfolio, asset, strategy version) rather than on a position
 * row, because `positions` is a ledger of executions and a holding is their
 * running result.
 */
class PositionRiskState extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'portfolio_id' => 'integer',
        'asset_id' => 'integer',
        'qty_shares' => 'float',
        'entry_price' => 'float',
        'opened_at' => 'date',
        'highest_price_since_entry' => 'float',
        'trailing_active' => 'boolean',
        'trailing_activated_at' => 'date',
        'trailing_activation_price' => 'float',
        'profit_floor_price' => 'float',
        'trailing_stop_price' => 'float',
        'initial_stop_price' => 'float',
        'effective_stop_price' => 'float',
        'stop_updated_at' => 'date',
        'evaluated_through' => 'date',
        'latest_reasons' => 'array',
        'closed' => 'boolean',
        'closed_at' => 'date',
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

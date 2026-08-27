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
        // Datetime rather than date: several fills can land on one day, and
        // the calculator's running average cost depends on their true order.
        'executed_at' => 'datetime',
    ];

    /**
     * Rows the Stockbit history importer created from a real broker execution.
     */
    public const SOURCE_STOCKBIT = 'stockbit';

    /**
     * A synthetic opening position derived from a broker snapshot, created
     * only when the user explicitly asked for it because no history covered
     * the holding. Never to be mistaken for a real historical BUY.
     */
    public const SOURCE_STOCKBIT_SNAPSHOT = 'stockbit_snapshot';

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

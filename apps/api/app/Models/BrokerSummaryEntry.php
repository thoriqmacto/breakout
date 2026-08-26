<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One broker's position within a broker-summary window.
 *
 * `side` records which of Stockbit's two ranked lists the row came from. Both
 * lists hold *net* positions over the window, so the buy and sell rows are not
 * two legs of a trade and net_value is never buy_value - sell_value.
 */
class BrokerSummaryEntry extends Model
{
    use HasFactory;

    public const SIDE_BUY = 'buy';

    public const SIDE_SELL = 'sell';

    protected $fillable = [
        'broker_summary_window_id',
        'broker_code',
        'side',
        'broker_type',
        'frequency',
        'source_date',
        'net_lot',
        'net_value',
        'gross_volume',
        'gross_value',
        'average_price',
    ];

    protected $casts = [
        'frequency' => 'int',
        'source_date' => 'date:Y-m-d',
        'net_lot' => 'int',
        'net_value' => 'float',
        'gross_volume' => 'int',
        'gross_value' => 'float',
        'average_price' => 'float',
    ];

    public function window(): BelongsTo
    {
        return $this->belongsTo(BrokerSummaryWindow::class, 'broker_summary_window_id');
    }
}

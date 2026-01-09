<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerSummaryFact extends Model
{
    protected $table = 'broker_summary_facts';

    protected $fillable = [
        'asset_id',
        'trade_date',
        'broker_code',
        'transaction_type',
        'broker_type',
        'buy_lot',
        'buy_volume',
        'buy_value',
        'buy_value_v',
        'buy_avg_price',
        'sell_lot',
        'sell_volume',
        'sell_value',
        'sell_value_v',
        'sell_avg_price',
        'net_lot',
        'net_volume',
        'net_value',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'buy_lot' => 'int',
        'buy_volume' => 'int',
        'buy_value' => 'float',
        'buy_value_v' => 'float',
        'buy_avg_price' => 'float',
        'sell_lot' => 'int',
        'sell_volume' => 'int',
        'sell_value' => 'float',
        'sell_value_v' => 'float',
        'sell_avg_price' => 'float',
        'net_lot' => 'int',
        'net_volume' => 'int',
        'net_value' => 'float',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

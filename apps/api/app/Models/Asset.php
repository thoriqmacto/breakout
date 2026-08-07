<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'lot_size' => 'integer',
        'tick_size' => 'float',
        'float' => 'float',
        'ipo_date' => 'date',
        'ipo_price' => 'float',
        'marketcap' => 'float',
        'address' => 'array',
        'history' => 'array',
        'key_executive' => 'array',
        'secretary' => 'array',
        'shareholder' => 'array',
        'subsidiary' => 'array',
        'profile' => 'array',
        'fee' => 'array',
        'asset_allocation' => 'array',
        'shareholder_reksa' => 'array',
        'pdf' => 'array',
        'shareholder_numbers' => 'array',
        'badges' => 'array',
        'top_holdings' => 'array',
        'shareholder_director_commissioner' => 'array',
        'listing_information' => 'array',
        'ticker_profile_payload' => 'array',
        'profile_synced_at' => 'datetime',
        'sync_price' => 'boolean',
        'sync_profile' => 'boolean',
        'sync_broker_summary' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(Price::class);
    }

    public function metric()
    {
        return $this->hasOne(Metric::class);
    }

    /**
     * Fetch the latest price record associated with the asset.
     *
     * This leverages the relationship with the Price model to obtain
     * the most recent OHLCV values and their corresponding date.
     *
     * @return \App\Models\Price|null
     */
    public function latestPrice()
    {
        return $this->latestPriceRecord()->first();
    }

    public function latestPriceRecord()
    {
        return $this->hasOne(Price::class)->latestOfMany('date');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model {
    protected $fillable = ['symbol','name','lot_size','tick_size'];
    public $timestamps = false;

    public function prices()
    {
        return $this->hasMany(Price::class);
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

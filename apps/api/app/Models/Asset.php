<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model {
    protected $fillable = ['symbol','name','lot_size','tick_size'];

    public function prices()
    {
        return $this->hasMany(Price::class);
    }
}

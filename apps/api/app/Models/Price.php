<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Price extends Model {
    protected $fillable=['asset_id','date','open','high','low','close','volume'];
    protected $casts=['date'=>'date'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}

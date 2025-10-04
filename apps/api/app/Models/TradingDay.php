<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradingDay extends Model
{
    use HasFactory;

    protected $table = 'trading_days';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'date',
        'close',
    ];

    protected $casts = [
        'date' => 'date',
        'close' => 'float',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradingCalendarDay extends Model
{
    use HasFactory;

    protected $table = 'trading_calendar';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'date',
        'is_trading_day',
        'is_weekend',
        'is_holiday',
        'holiday_reason',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'is_trading_day' => 'boolean',
        'is_weekend' => 'boolean',
        'is_holiday' => 'boolean',
    ];
}

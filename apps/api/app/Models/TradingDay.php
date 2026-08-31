<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * Store the primary key as a bare calendar date.
     *
     * The `date` cast hands Eloquent a Carbon on write, which it serialises
     * with the model's datetime format -- so a model write stored
     * "2026-08-28 00:00:00" while every query-builder write stored
     * "2026-08-28". On an engine that does not coerce the column those are two
     * different primary keys for one session: an upsert keyed on the short
     * form never conflicts with the long one, so instead of repairing the row
     * it inserts a second one, and the older NULL then shadows the good close
     * on any ordered read.
     *
     * A mutator takes precedence over the cast on write, so this normalises
     * every model write while reads still come back as Carbon.
     */
    public function setDateAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['date'] = null;

            return;
        }

        if ($value instanceof \DateTimeInterface) {
            $this->attributes['date'] = $value->format('Y-m-d');

            return;
        }

        try {
            $this->attributes['date'] = Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            $this->attributes['date'] = $value;
        }
    }
}

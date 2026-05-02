<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'year' => 'integer',
        'cash_balance' => 'float',
    ];

    public function positionsForYear(?int $year = null): HasMany
    {
        $resolvedYear = $year ?? $this->year;

        return $this->positions()->when(
            $resolvedYear,
            fn ($query) => $query->whereYear('executed_at', $resolvedYear)
        );
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

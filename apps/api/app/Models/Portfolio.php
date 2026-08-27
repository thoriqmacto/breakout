<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    /**
     * Sentinel for "do not filter by year". Imported history routinely spans
     * years, and a year-filtered view must not be able to hide it.
     */
    public const ALL_YEARS = 'all';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'year' => 'integer',
        'cash_balance' => 'float',
    ];

    /**
     * Positions filtered to a presentation year.
     *
     * The portfolio owns the complete ledger -- a 2025 trade is part of a 2026
     * portfolio's history and is what explains its cost basis. `year` is a
     * view over that ledger, never ownership of the rows, so
     * `positionsForYear(self::ALL_YEARS)` returns everything and the
     * calculator always reads the unfiltered relation.
     */
    public function positionsForYear(int|string|null $year = null): HasMany
    {
        if ($year === self::ALL_YEARS) {
            return $this->positions();
        }

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

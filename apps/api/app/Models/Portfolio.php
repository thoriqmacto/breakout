<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'year' => 'integer',
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
}

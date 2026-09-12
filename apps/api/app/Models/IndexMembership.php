<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One symbol's spell in one index.
 *
 * `removed_on` null means current. A departed member keeps its row so the
 * dashboard can say what left and when, and so a symbol that returns after a
 * review is recognisable as a return.
 */
class IndexMembership extends Model
{
    protected $guarded = ['id'];

    /**
     * The three date columns are deliberately left uncast, as ISO date
     * strings.
     *
     * A `date` cast writes `2026-09-12 00:00:00` through the model and the
     * plain date through a query-builder update, so the same column ended up
     * holding two spellings of one day depending on which path wrote it. These
     * are days, never instants; keeping them as `Y-m-d` text makes every
     * writer agree and makes a comparison in SQL mean what it reads like.
     */
    protected $casts = [];

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('removed_on');
    }

    public function isCurrent(): bool
    {
        return $this->removed_on === null;
    }
}

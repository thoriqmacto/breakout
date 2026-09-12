<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One published index, and when its membership was last confirmed.
 *
 * The row exists to date the list. "70 members" without "as of" is the claim
 * that broke the badge in the first place: a membership read once and never
 * again looks identical to one read this morning.
 */
class MarketIndex extends Model
{
    /**
     * Laravel pluralises this class to `market_indices`, which is not the
     * table. "Indexes" is the name in the migration because these are lists of
     * stocks, not database indexes, and the two would read as the same thing.
     */
    protected $table = 'market_indexes';

    protected $guarded = ['id'];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_effective_on' => 'date',
        'member_count' => 'integer',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(IndexMembership::class, 'index_code', 'code');
    }
}

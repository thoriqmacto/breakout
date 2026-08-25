<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Strategy extends Model
{
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_PUBLIC = 'public';

    protected $table = 'strategies';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'copied_from_id' => 'integer',
        'rules' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'last_match_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(StrategyRun::class);
    }

    public function latestRun(): HasMany
    {
        return $this->runs()->latest('id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->id;
    }

    /**
     * Everything the given user is allowed to see: their own strategies plus
     * every public one. Editing is a stricter check -- see isOwnedBy.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('visibility', self::VISIBILITY_PUBLIC);

            if ($user !== null) {
                $inner->orWhere('user_id', $user->id);
            }
        });
    }
}

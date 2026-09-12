<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A persistent in-app attention state, e.g. "the Stockbit token expires in
 * four hours". Keyed on (type, key) so a daily check that finds the same
 * problem again updates the existing row instead of adding another.
 */
class AutomationAlert extends Model
{
    public const TYPE_STOCKBIT_TOKEN = 'stockbit_token';

    /**
     * The Google Drive grant.
     *
     * Separate from the Stockbit token because they fail independently and
     * are fixed by different people doing different things -- and because a
     * refresh token has no readable expiry, so this one can only ever be
     * raised by a probe that actually spent it.
     */
    public const TYPE_GOOGLE_DRIVE = 'google_drive';

    /**
     * A strategy a user asked to be told about fired on an asset.
     *
     * Its own type because it is the only alert that is not about the system
     * being broken: the others say something needs fixing, this one says
     * something needs deciding, and mixing them would make "no alerts" stop
     * meaning "nothing is wrong".
     */
    public const TYPE_STRATEGY_SIGNAL = 'strategy_signal';

    /**
     * A published index's membership could not be refreshed.
     *
     * Its own type because the consequence is quiet: nothing breaks, the
     * badges simply go on describing whatever was last read. Without a row
     * saying so, "the index has not changed in three weeks" and "the reader
     * has been broken for three weeks" look exactly alike.
     */
    public const TYPE_INDEX_MEMBERSHIP = 'index_membership';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}

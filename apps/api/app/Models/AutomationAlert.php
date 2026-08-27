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

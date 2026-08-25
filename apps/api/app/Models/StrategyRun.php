<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'strategy_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'strategy_id' => 'integer',
        'scan_date' => 'date:Y-m-d',
        'evaluated_count' => 'integer',
        'matched_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(StrategyRunMatch::class);
    }
}

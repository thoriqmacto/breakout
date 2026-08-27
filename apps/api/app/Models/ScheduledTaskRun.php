<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution -- or one deliberate non-execution -- of a scheduled task.
 *
 * A skipped run is a first-class record, not an absence: "the calendar said
 * today is a holiday" and "the dispatcher never fired" look identical in a
 * table that only stores successes.
 */
class ScheduledTaskRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED_TOKEN = 'blocked_token';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_BLOCKED_TOKEN,
    ];

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
        'metadata' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }

    public function isTerminal(): bool
    {
        return ! in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }
}

<?php

namespace App\Models;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One database-managed Artisan schedule.
 *
 * The command is a name from the config/automation.php allowlist and the
 * parameters are structured; neither is ever concatenated into a shell string.
 */
class ScheduledTask extends Model
{
    public const CONDITION_NONE = 'none';

    public const CONDITION_TRADING_DAY = 'trading_day';

    public const CONDITION_LAST_TRADING_DAY_OF_WEEK = 'last_trading_day_of_week';

    public const CONDITIONS = [
        self::CONDITION_NONE,
        self::CONDITION_TRADING_DAY,
        self::CONDITION_LAST_TRADING_DAY_OF_WEEK,
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'parameters' => 'array',
        'priority' => 'integer',
        'enabled' => 'boolean',
        'sync_gdrive_after_success' => 'boolean',
        'is_system' => 'boolean',
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }

    public function latestRun(): HasMany
    {
        return $this->runs()->latest('id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The arguments half of `parameters`, always an array.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        $parameters = $this->parameters ?? [];

        return is_array($parameters['arguments'] ?? null) ? $parameters['arguments'] : [];
    }

    /**
     * The options half of `parameters`, always an array.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $parameters = $this->parameters ?? [];

        return is_array($parameters['options'] ?? null) ? $parameters['options'] : [];
    }

    public function resolvedTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->timezone ?: config('automation.timezone')));
        } catch (Throwable) {
            // A row whose timezone somehow became unusable must still be
            // interpretable rather than fatal to the whole dispatcher pass.
            return new DateTimeZone((string) config('automation.timezone', 'Asia/Jakarta'));
        }
    }

    public function cron(): ?CronExpression
    {
        try {
            return new CronExpression((string) $this->cron_expression);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The next occurrence after $after, as a UTC instant.
     *
     * The expression is evaluated in the task's own timezone, which is what
     * makes "0 16 * * *" mean 16:00 WIB and not 16:00 UTC.
     */
    public function nextRunAt(?Carbon $after = null): ?Carbon
    {
        $cron = $this->cron();

        if ($cron === null) {
            return null;
        }

        $zone = $this->resolvedTimezone();
        $reference = ($after ?? Carbon::now())->copy()->setTimezone($zone);

        try {
            $next = $cron->getNextRunDate($reference, 0, false, $zone->getName());
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($next)->utc();
    }
}

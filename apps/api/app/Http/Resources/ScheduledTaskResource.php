<?php

namespace App\Http\Resources;

use App\Models\ScheduledTask;
use App\Services\Automation\CommandRegistry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledTask
 */
class ScheduledTaskResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        $registry = app(CommandRegistry::class);
        $next = $this->nextRunAt();
        $latest = $this->relationLoaded('latestRun') ? $this->latestRun->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'command' => $this->command,
            'parameters' => [
                'arguments' => $this->arguments(),
                'options' => $this->options(),
            ],
            // Presentation only. The backend never parses this back into
            // parameters, and nothing executes it.
            'command_preview' => $registry->preview((string) $this->command, (array) ($this->parameters ?? [])),
            'command_allowed' => $registry->has((string) $this->command),
            'stockbit_bulk' => $registry->isStockbitBulk((string) $this->command),
            'cron_expression' => $this->cron_expression,
            'timezone' => $this->timezone,
            'condition' => $this->condition,
            'priority' => $this->priority,
            'enabled' => (bool) $this->enabled,
            'sync_gdrive_after_success' => (bool) $this->sync_gdrive_after_success,
            'is_system' => (bool) $this->is_system,
            'last_run_at' => optional($this->last_run_at)->toIso8601String(),
            'last_success_at' => optional($this->last_success_at)->toIso8601String(),
            'last_failure_at' => optional($this->last_failure_at)->toIso8601String(),
            'next_run_at' => optional($next)->toIso8601String(),
            'next_run_local' => $next
                ? $next->copy()->setTimezone($this->resolvedTimezone())->format('Y-m-d H:i')
                : null,
            'latest_run' => $latest ? ScheduledTaskRunResource::make($latest)->resolve($request) : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

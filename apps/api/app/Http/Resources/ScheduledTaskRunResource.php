<?php

namespace App\Http\Resources;

use App\Models\ScheduledTaskRun;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledTaskRun
 */
class ScheduledTaskRunResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => $this->id,
            'scheduled_task_id' => $this->scheduled_task_id,
            'scheduled_for' => optional($this->scheduled_for)->toIso8601String(),
            'trigger' => $this->trigger,
            'status' => $this->status,
            'skip_reason' => $this->skip_reason,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'exit_code' => $this->exit_code,
            // Already redacted and length-capped when it was stored; see
            // App\Services\Automation\OutputSanitizer.
            'output' => $this->output,
            'error' => $this->error,
            'metadata' => $metadata,
            // Lifted out of metadata so the history table can render columns
            // without every client re-deriving the same keys.
            'market_date' => $metadata['market_date'] ?? null,
            'range_from' => $metadata['range_from'] ?? null,
            'range_to' => $metadata['range_to'] ?? null,
            'ticker_count' => $metadata['ticker_count'] ?? null,
            'success_ticker_count' => $metadata['success_ticker_count'] ?? null,
            'failed_ticker_count' => $metadata['failed_ticker_count'] ?? null,
            'partial' => (bool) ($metadata['partial'] ?? false),
            'gdrive' => $metadata['gdrive'] ?? $metadata['gdrive_bars'] ?? null,
            'gdrive_broker_summary' => $metadata['gdrive_broker_summary'] ?? null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}

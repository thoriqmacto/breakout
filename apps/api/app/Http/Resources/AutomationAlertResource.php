<?php

namespace App\Http\Resources;

use App\Models\AutomationAlert;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationAlert
 */
class AutomationAlertResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'key' => $this->key,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            // Only ever safe facts: a token's status, source, fingerprint and
            // expiry. Never a credential.
            'context' => is_array($this->context) ? $this->context : [],
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

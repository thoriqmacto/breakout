<?php

namespace App\Services\Automation;

use App\Models\AutomationAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Raising and clearing the dashboard's persistent attention states.
 *
 * The project has no mail or push transport, and adding a third-party
 * notification dependency for one token reminder would be a poor trade. So a
 * reminder is a row: it survives a reload, it is shown in the dashboard
 * header and on the Automation page, and it clears itself when the underlying
 * condition does.
 *
 * Raising is keyed on (type, key), so the daily check re-raising the same
 * warning updates one row rather than accumulating one per day.
 */
class AutomationAlerts
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function raise(
        string $type,
        string $key,
        string $severity,
        string $title,
        string $message,
        array $context = [],
    ): AutomationAlert {
        $alert = AutomationAlert::query()->firstOrNew(['type' => $type, 'key' => $key]);

        $alert->fill([
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'resolved_at' => null,
        ]);

        $alert->save();

        return $alert;
    }

    /**
     * Close an alert if one is open. Returns whether anything changed, so a
     * caller can report "nothing to do" honestly.
     */
    public function resolve(string $type, string $key): bool
    {
        return AutomationAlert::query()
            ->where('type', $type)
            ->where('key', $key)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Carbon::now()]) > 0;
    }

    /**
     * @return Collection<int, AutomationAlert>
     */
    public function open(): Collection
    {
        return AutomationAlert::query()
            ->open()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get();
    }
}

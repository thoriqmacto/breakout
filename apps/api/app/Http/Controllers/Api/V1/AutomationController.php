<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\AutomationAlertResource;
use App\Http\Resources\ScheduledTaskRunResource;
use App\Models\AutomationAlert;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Automation\TradingWeekResolver;
use App\Services\BrokerSummaryArchiveMirror;
use App\Services\GoogleDriveHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The header of /dashboard/automation: is the scheduler alive, is the token
 * usable, is cold storage reachable, and what runs next.
 */
class AutomationController extends ApiController
{
    /**
     * Drive is probed over the network, so a page that several tabs poll
     * should not re-probe on every request.
     */
    private const DRIVE_CACHE_SECONDS = 60;

    public function status(
        Request $request,
        StockbitTokenHealth $tokenHealth,
        GoogleDriveHealth $driveHealth,
        BrokerSummaryArchiveMirror $archiveMirror,
        TradingWeekResolver $calendar,
        AutomationAlerts $alerts,
    ) {
        $timezone = (string) config('automation.timezone', 'Asia/Jakarta');
        $now = Carbon::now();

        $tasks = ScheduledTask::query()->enabled()->orderBy('priority')->orderBy('id')->get();

        $next = null;

        foreach ($tasks as $task) {
            $candidate = $task->nextRunAt($now);

            if ($candidate === null) {
                continue;
            }

            if ($next === null || $candidate->lessThan($next['at'])) {
                $next = ['at' => $candidate, 'task' => $task];
            }
        }

        $lastDispatch = ScheduledTaskRun::query()
            ->where('trigger', ScheduledTaskRun::TRIGGER_SCHEDULE)
            ->orderByDesc('id')
            ->first();

        $today = $calendar->today($now);
        $day = $calendar->describeDay($today);
        $week = $calendar->describeLastTradingDayOfWeek($now);

        $drive = $request->boolean('fresh')
            ? $driveHealth->check('gdrive')
            : Cache::remember(
                'automation:drive-health',
                self::DRIVE_CACHE_SECONDS,
                static fn () => $driveHealth->check('gdrive'),
            );

        return ApiResponse::success([
            'scheduler' => [
                'timezone' => $timezone,
                'timezone_label' => $timezone === 'Asia/Jakarta' ? 'Asia/Jakarta (WIB / UTC+7)' : $timezone,
                'application_timezone' => (string) config('app.timezone'),
                'now_utc' => $now->toIso8601String(),
                'now_local' => $now->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
                'dispatcher_command' => 'scheduler:dispatch',
                'enabled_task_count' => $tasks->count(),
                'total_task_count' => ScheduledTask::query()->count(),
                // The dispatcher runs every minute, so a last scheduled run
                // far in the past is the signal that cron is not calling
                // schedule:run at all.
                'last_scheduled_run_at' => optional($lastDispatch?->created_at)->toIso8601String(),
                'next_run' => $next === null ? null : [
                    'task_id' => $next['task']->id,
                    'slug' => $next['task']->slug,
                    'name' => $next['task']->name,
                    'at' => $next['at']->toIso8601String(),
                    'at_local' => $next['at']->copy()->setTimezone($next['task']->resolvedTimezone())->format('Y-m-d H:i'),
                    'timezone' => $next['task']->timezone,
                ],
            ],
            'stockbit_token' => $tokenHealth->status(),
            'google_drive' => [
                'health' => $drive,
                'broker_summary_mirror_disk' => $archiveMirror->resolveDisk(),
                'bars_mirror_disk' => config('csv.mirror_disk'),
            ],
            'trading_calendar' => [
                'today' => $day['date'],
                'today_known' => $day['known'],
                'today_is_trading_day' => $day['is_trading_day'],
                'week_status' => $week['status'],
                'week_from' => $week['from'],
                'week_to' => $week['to'],
                'today_is_last_trading_day' => $week['is_last'],
                'missing_dates' => $week['missing_dates'],
            ],
            'alerts' => AutomationAlertResource::collection($alerts->open())->resolve($request),
        ]);
    }

    /**
     * Open attention states. Kept small and cheap: the dashboard header polls
     * this on every authenticated page.
     */
    public function alerts(Request $request, AutomationAlerts $alerts)
    {
        return ApiResponse::success(
            AutomationAlertResource::collection($alerts->open()),
        );
    }

    public function dismissAlert(Request $request, AutomationAlert $alert, AutomationAlerts $alerts)
    {
        $alerts->resolve($alert->type, $alert->key);

        return ApiResponse::success(null, 'Alert dismissed.');
    }

    /**
     * The most recent runs across every task, for the activity feed.
     */
    public function runs(Request $request)
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = ScheduledTaskRun::query()
            ->with('task:id,name,slug,command')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25);

        return ApiResponse::success(
            ScheduledTaskRunResource::collection($runs->getCollection()),
            null,
            200,
            [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'tasks' => $runs->getCollection()
                    ->pluck('task')
                    ->filter()
                    ->unique('id')
                    ->map(static fn ($task): array => [
                        'id' => $task->id,
                        'name' => $task->name,
                        'slug' => $task->slug,
                        'command' => $task->command,
                    ])
                    ->values()
                    ->all(),
            ],
        );
    }
}

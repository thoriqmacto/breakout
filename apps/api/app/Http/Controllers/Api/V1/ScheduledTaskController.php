<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\ScheduledTaskResource;
use App\Http\Resources\ScheduledTaskRunResource;
use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Automation\CommandRegistry;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * CRUD for the database-managed scheduler.
 *
 * The dashboard can create and edit automations freely, but it can never
 * describe work outside the allowlist: `command` must name an entry in
 * config/automation.php, and `parameters` is validated against that entry's
 * declared arguments and options by CommandRegistry before it is stored. What
 * is persisted is a command name and a structured map -- never a command line,
 * and never anything a shell sees.
 */
class ScheduledTaskController extends ApiController
{
    public function __construct(private readonly CommandRegistry $registry) {}

    public function index(Request $request)
    {
        $tasks = ScheduledTask::query()
            ->with('latestRun')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            ScheduledTaskResource::collection($tasks),
            null,
            200,
            [
                'timezone' => config('automation.timezone'),
                'application_timezone' => config('app.timezone'),
                'conditions' => ScheduledTask::CONDITIONS,
                'commands' => $this->registry->catalog(),
            ],
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        if (! is_array($validated)) {
            return $validated;
        }

        $parameters = $this->validateParameters($validated['command'], $validated['parameters'] ?? []);

        if (! is_array($parameters)) {
            return $parameters;
        }

        $task = ScheduledTask::query()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['slug'] ?? $validated['name']),
            'description' => $validated['description'] ?? null,
            'command' => $validated['command'],
            'parameters' => $parameters,
            'cron_expression' => $validated['cron_expression'],
            'timezone' => $validated['timezone'] ?? config('automation.timezone', 'Asia/Jakarta'),
            'condition' => $validated['condition'] ?? ScheduledTask::CONDITION_NONE,
            'priority' => $validated['priority'] ?? 100,
            'enabled' => $validated['enabled'] ?? true,
            'sync_gdrive_after_success' => $validated['sync_gdrive_after_success'] ?? false,
            'is_system' => false,
        ]);

        return ApiResponse::success(
            ScheduledTaskResource::make($task->load('latestRun')),
            'Automation created.',
            201,
        );
    }

    public function show(ScheduledTask $scheduledTask)
    {
        return ApiResponse::success(ScheduledTaskResource::make($scheduledTask->load('latestRun')));
    }

    public function update(Request $request, ScheduledTask $scheduledTask)
    {
        $validated = $this->validatePayload($request, $scheduledTask);

        if (! is_array($validated)) {
            return $validated;
        }

        $command = $validated['command'] ?? $scheduledTask->command;
        $given = array_key_exists('parameters', $validated)
            ? $validated['parameters']
            : (array) ($scheduledTask->parameters ?? []);

        $parameters = $this->validateParameters($command, $given);

        if (! is_array($parameters)) {
            return $parameters;
        }

        $attributes = array_intersect_key($validated, array_flip([
            'name', 'description', 'command', 'cron_expression', 'timezone',
            'condition', 'priority', 'enabled', 'sync_gdrive_after_success',
        ]));

        $attributes['parameters'] = $parameters;

        // The slug is the stable handle a seeded automation is recognised by,
        // so it is only rewritten when explicitly given.
        if (array_key_exists('slug', $validated) && is_string($validated['slug']) && $validated['slug'] !== '') {
            $attributes['slug'] = $this->uniqueSlug($validated['slug'], $scheduledTask->id);
        }

        $scheduledTask->fill($attributes)->save();

        return ApiResponse::success(
            ScheduledTaskResource::make($scheduledTask->fresh()->load('latestRun')),
            'Automation updated.',
        );
    }

    public function destroy(ScheduledTask $scheduledTask)
    {
        $wasSystem = (bool) $scheduledTask->is_system;

        $scheduledTask->delete();

        return ApiResponse::success(null, $wasSystem
            ? 'Automation deleted. It can be restored with "php artisan db:seed --class=AutomationSeeder".'
            : 'Automation deleted.');
    }

    public function toggle(Request $request, ScheduledTask $scheduledTask)
    {
        $data = $request->validate(['enabled' => ['sometimes', 'boolean']]);

        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : ! $scheduledTask->enabled;

        $scheduledTask->forceFill(['enabled' => $enabled])->save();

        return ApiResponse::success(
            ScheduledTaskResource::make($scheduledTask->fresh()->load('latestRun')),
            $enabled ? 'Automation enabled.' : 'Automation disabled.',
        );
    }

    /**
     * Run now.
     *
     * The run row is created here so the client has something to poll, and the
     * work is handed to a queue worker: a bulk scrape is an hour of API calls
     * and has no business inside an HTTP request.
     *
     * The market condition still applies by default -- "run now" on a public
     * holiday should not call Stockbit -- and `force` is how someone
     * deliberately overrides that.
     */
    public function run(Request $request, ScheduledTask $scheduledTask)
    {
        $data = $request->validate(['force' => ['sometimes', 'boolean']]);

        if (! $scheduledTask->enabled && ! ($data['force'] ?? false)) {
            return ApiResponse::error(
                'This automation is disabled. Enable it, or pass force to run it once anyway.',
                422,
            );
        }

        if (! $this->registry->has((string) $scheduledTask->command)) {
            return ApiResponse::error(
                sprintf('"%s" is no longer on the automation command allowlist.', (string) $scheduledTask->command),
                422,
            );
        }

        $run = ScheduledTaskRun::query()->create([
            'scheduled_task_id' => $scheduledTask->id,
            'scheduled_for' => null,
            'trigger' => ScheduledTaskRun::TRIGGER_MANUAL,
            'status' => ScheduledTaskRun::STATUS_PENDING,
        ]);

        RunScheduledTaskJob::dispatch($scheduledTask->id, $run->id, (bool) ($data['force'] ?? false));

        return ApiResponse::success(
            ScheduledTaskRunResource::make($run->fresh()),
            'Run queued.',
            202,
        );
    }

    public function runs(Request $request, ScheduledTask $scheduledTask)
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(ScheduledTaskRun::STATUSES)],
        ]);

        $query = $scheduledTask->runs()->orderByDesc('id');

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $runs = $query->paginate($data['per_page'] ?? 25);

        return ApiResponse::success(
            ScheduledTaskRunResource::collection($runs->getCollection()),
            null,
            200,
            [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
            ],
        );
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function validatePayload(Request $request, ?ScheduledTask $existing = null)
    {
        $required = $existing === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:191'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // The allowlist, enforced as a validation rule so an unapproved
            // command is rejected with a field error rather than at execution.
            'command' => [$required, 'string', Rule::in($this->registry->names())],

            'parameters' => ['sometimes', 'array'],
            'parameters.arguments' => ['sometimes', 'array'],
            'parameters.options' => ['sometimes', 'array'],

            'cron_expression' => [$required, 'string', 'max:191', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                    $fail('The schedule must be a valid five-field cron expression, e.g. "0 16 * * *".');
                }
            }],

            'timezone' => ['sometimes', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
            'condition' => ['sometimes', 'string', Rule::in(ScheduledTask::CONDITIONS)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'enabled' => ['sometimes', 'boolean'],
            'sync_gdrive_after_success' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|JsonResponse
     */
    private function validateParameters(string $command, array $parameters)
    {
        try {
            return $this->registry->validate($command, $parameters);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                422,
                ['parameters' => [$exception->getMessage()]],
            );
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'automation';
        $slug = $base;
        $suffix = 2;

        while (ScheduledTask::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

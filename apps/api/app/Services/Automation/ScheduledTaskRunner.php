<?php

namespace App\Services\Automation;

use App\Models\AutomationAlert;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\BarCsvMirror;
use App\Services\BrokerSummaryArchiveMirror;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Executes one scheduled task and records exactly what happened.
 *
 * The three protections that make this safe to point at production are all
 * here, and all of them are enforced regardless of who asked for the run:
 *
 *  - Duplicate protection is the unique index on (scheduled_task_id,
 *    scheduled_for). Two dispatcher passes, or two servers, that both decide
 *    the 16:00 occurrence is due will both try to insert; one wins and the
 *    other stands down having done nothing.
 *  - Overlap protection is a cache lock per task, so a scrape that overruns
 *    its next occurrence is never joined by a second copy of itself.
 *  - A second, shared lock serialises every bulk Stockbit job, so the daily
 *    OHLCV scrape and the weekly broker-summary scrape queue behind each other
 *    rather than hitting the API together.
 *
 * The command itself is invoked through Artisan with a structured parameter
 * map. No part of this file builds, quotes or executes a command line.
 */
class ScheduledTaskRunner
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly ConditionEvaluator $conditions,
        private readonly StockbitTokenHealth $tokenHealth,
        private readonly RunMetadata $metadata,
        private readonly OutputSanitizer $sanitizer,
        private readonly AutomationAlerts $alerts,
        private readonly BarCsvMirror $barMirror,
        private readonly BrokerSummaryArchiveMirror $archiveMirror,
    ) {}

    /**
     * Run a scheduled occurrence.
     *
     * Returns null when another process already claimed this exact occurrence,
     * which is a normal outcome and not an error.
     */
    public function runScheduled(ScheduledTask $task, Carbon $scheduledFor): ?ScheduledTaskRun
    {
        $run = $this->claim($task, $scheduledFor, ScheduledTaskRun::TRIGGER_SCHEDULE);

        if ($run === null) {
            return null;
        }

        return $this->execute($task, $run, $scheduledFor);
    }

    /**
     * Run on demand from the dashboard or the CLI.
     *
     * A manual run is not an occurrence: it carries a null scheduled_for and
     * is exempt from duplicate protection, because asking twice deliberately
     * is a legitimate thing to do. Overlap protection still applies.
     */
    public function runManually(ScheduledTask $task, bool $ignoreCondition = true): ScheduledTaskRun
    {
        $run = $this->claim($task, null, ScheduledTaskRun::TRIGGER_MANUAL);

        // A null scheduled_for cannot collide, so claim() always succeeds here.
        return $this->execute($task, $run, Carbon::now(), $ignoreCondition);
    }

    /**
     * Execute a run row that has already been created, e.g. one the API
     * inserted so it could hand the client something to poll before handing
     * the work to a queue worker.
     */
    public function runPrepared(ScheduledTask $task, ScheduledTaskRun $run, bool $ignoreCondition = false): ScheduledTaskRun
    {
        return $this->execute($task, $run, Carbon::now(), $ignoreCondition);
    }

    /**
     * Insert the run row that reserves this occurrence.
     */
    private function claim(ScheduledTask $task, ?Carbon $scheduledFor, string $trigger): ?ScheduledTaskRun
    {
        try {
            return ScheduledTaskRun::create([
                'scheduled_task_id' => $task->id,
                'scheduled_for' => $scheduledFor?->copy()->utc(),
                'trigger' => $trigger,
                'status' => ScheduledTaskRun::STATUS_PENDING,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23000/23505 cover MySQL/MariaDB, SQLite and PostgreSQL.
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function execute(
        ScheduledTask $task,
        ScheduledTaskRun $run,
        Carbon $moment,
        bool $ignoreCondition = false,
    ): ScheduledTaskRun {
        // Whatever the condition and locks decide, the attempt itself is a
        // fact about this task and belongs on the row.
        $task->forceFill(['last_run_at' => Carbon::now()])->save();

        if (! $this->registry->has((string) $task->command)) {
            // A row can outlive its allowlist entry -- someone removes a
            // command from the config, or edits the database directly. Refuse
            // rather than hand an unknown name to Artisan.
            return $this->finish($task, $run, ScheduledTaskRun::STATUS_FAILED, [
                'error' => sprintf('"%s" is not on the automation command allowlist.', (string) $task->command),
                'metadata' => ['command' => (string) $task->command],
            ]);
        }

        if (! $ignoreCondition) {
            $condition = $this->conditions->evaluate($task, $moment);

            if (! $condition['run']) {
                return $this->finish($task, $run, ScheduledTaskRun::STATUS_SKIPPED, [
                    'skip_reason' => $condition['reason'],
                    'error' => $condition['message'],
                    'metadata' => $condition['metadata'],
                ]);
            }

            $baseMetadata = $condition['metadata'];
        } else {
            $baseMetadata = ['condition' => 'ignored_manual_run'];
        }

        $taskLock = Cache::lock(
            'automation:task:'.$task->id,
            max(60, (int) config('automation.locks.task_seconds', 7200)),
        );

        if (! $taskLock->get()) {
            return $this->finish($task, $run, ScheduledTaskRun::STATUS_SKIPPED, [
                'skip_reason' => 'overlapping_run',
                'error' => 'A previous run of this task is still in progress.',
                'metadata' => $baseMetadata,
            ]);
        }

        try {
            return $this->executeLocked($task, $run, $baseMetadata);
        } finally {
            $this->release($taskLock);
        }
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     */
    private function executeLocked(ScheduledTask $task, ScheduledTaskRun $run, array $baseMetadata): ScheduledTaskRun
    {
        $isBulkStockbit = $this->registry->isStockbitBulk((string) $task->command);
        $stockbitLock = null;

        if ($isBulkStockbit) {
            $preflight = $this->tokenHealth->preflight();
            $baseMetadata['token'] = $this->publicTokenFacts($preflight['status']);

            if (! $preflight['ok']) {
                // Nothing is called, nothing is written, and the reason names
                // the remedy. Starting an hour-long scrape on a token that is
                // about to die is how a day ends up half-imported.
                $this->raiseTokenAlert($preflight);

                return $this->finish($task, $run, ScheduledTaskRun::STATUS_BLOCKED_TOKEN, [
                    'skip_reason' => $preflight['reason'],
                    'error' => $preflight['message'],
                    'metadata' => $baseMetadata,
                ]);
            }

            $stockbitLock = Cache::lock(
                'automation:stockbit-bulk',
                max(60, (int) config('automation.locks.stockbit_seconds', 7200)),
            );

            $wait = max(0, (int) config('automation.locks.stockbit_wait_seconds', 3000));

            if (! $this->acquireWithWait($stockbitLock, $wait)) {
                return $this->finish($task, $run, ScheduledTaskRun::STATUS_SKIPPED, [
                    'skip_reason' => 'stockbit_busy',
                    'error' => 'Another bulk Stockbit job held the shared lock for the whole wait window.',
                    'metadata' => $baseMetadata,
                ]);
            }
        }

        try {
            return $this->invoke($task, $run, $baseMetadata);
        } finally {
            if ($stockbitLock !== null) {
                $this->release($stockbitLock);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     */
    private function invoke(ScheduledTask $task, ScheduledTaskRun $run, array $baseMetadata): ScheduledTaskRun
    {
        $startedAt = Carbon::now();
        $startedMs = (int) round(microtime(true) * 1000);

        $run->forceFill([
            'status' => ScheduledTaskRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ])->save();

        $this->metadata->reset();

        $buffer = new BufferedOutput;
        $exitCode = null;
        $error = null;

        try {
            $parameters = $this->registry->toArtisanParameters(
                (string) $task->command,
                (array) ($task->parameters ?? []),
            );

            Log::info('automation.task.started', [
                'task_id' => $task->id,
                'slug' => $task->slug,
                'command' => $task->command,
                'run_id' => $run->id,
                'trigger' => $run->trigger,
            ]);

            $exitCode = Artisan::call((string) $task->command, $parameters, $buffer);
        } catch (Throwable $exception) {
            $exitCode = 1;
            $error = $exception->getMessage();

            Log::error('automation.task.threw', [
                'task_id' => $task->id,
                'slug' => $task->slug,
                'command' => $task->command,
                'run_id' => $run->id,
                // The message only; a stack trace in a browser-served field
                // is an information leak with no diagnostic value the log
                // does not already carry.
                'message' => $exception->getMessage(),
            ]);
        }

        $commandMetadata = $this->metadata->all();
        $this->metadata->reset();

        $metadata = array_replace($baseMetadata, $commandMetadata);
        $status = $exitCode === 0 ? ScheduledTaskRun::STATUS_SUCCESS : ScheduledTaskRun::STATUS_FAILED;

        // A command that reached its own token check and failed it reports
        // that state rather than a generic failure, so the dashboard can point
        // at the remedy instead of at an exit code.
        if ($status === ScheduledTaskRun::STATUS_FAILED && ($commandMetadata['blocked_token'] ?? false) === true) {
            $status = ScheduledTaskRun::STATUS_BLOCKED_TOKEN;
        }

        if (($commandMetadata['skipped'] ?? false) === true) {
            $status = ScheduledTaskRun::STATUS_SKIPPED;
        }

        if ($status === ScheduledTaskRun::STATUS_SUCCESS) {
            $metadata = array_replace($metadata, $this->syncColdStorage($task, $commandMetadata));
        }

        $error ??= $this->summarizeProblems($commandMetadata);

        return $this->finish($task, $run, $status, [
            'skip_reason' => is_string($commandMetadata['skip_reason'] ?? null) ? $commandMetadata['skip_reason'] : null,
            'exit_code' => $exitCode,
            'output' => $buffer->fetch(),
            'error' => $error,
            'metadata' => $metadata,
            'duration_ms' => (int) round(microtime(true) * 1000) - $startedMs,
        ]);
    }

    /**
     * Post-run cold-storage synchronisation for tasks that do not mirror their
     * own output.
     *
     * The daily and weekly automations know precisely which files they touched
     * and mirror those themselves; they say so with `mirror_handled`, and this
     * then does nothing. Without that flag two layers would upload the same
     * files, which on Drive means paying twice for the quota and racing each
     * other on the same paths.
     *
     * @param  array<string, mixed>  $commandMetadata
     * @return array<string, mixed>
     */
    private function syncColdStorage(ScheduledTask $task, array $commandMetadata): array
    {
        if (! $task->sync_gdrive_after_success) {
            return [];
        }

        if (($commandMetadata['mirror_handled'] ?? false) === true) {
            return [];
        }

        $result = [];

        try {
            $bars = $this->barMirror->flush();

            if ($bars['disk'] !== null) {
                $result['gdrive_bars'] = [
                    'disk' => $bars['disk'],
                    'uploaded' => count($bars['uploaded']),
                    'skipped_unchanged' => count($bars['skipped']),
                    'failed' => $bars['failed'],
                ];
            }
        } catch (Throwable $exception) {
            // Cold storage failing must never turn a successful data run into
            // a failure. The local files are the working copy and are intact.
            $result['gdrive_bars'] = ['status' => 'failed', 'message' => $exception->getMessage()];
        }

        try {
            $archive = $this->archiveMirror->mirrorAll();

            if ($archive['disk'] !== null) {
                $result['gdrive_broker_summary'] = $this->archiveMirror->summarize($archive);
            }
        } catch (Throwable $exception) {
            $result['gdrive_broker_summary'] = ['status' => 'failed', 'message' => $exception->getMessage()];
        }

        return $result;
    }

    /**
     * Turn the command's own report of partial failure into a sentence the run
     * history can show, so "success" is never the whole story when it is not.
     *
     * @param  array<string, mixed>  $commandMetadata
     */
    private function summarizeProblems(array $commandMetadata): ?string
    {
        $problems = [];

        $failed = $commandMetadata['failed_ticker_count'] ?? null;

        if (is_int($failed) && $failed > 0) {
            $problems[] = sprintf('%d ticker(s) failed.', $failed);
        }

        $gdrive = $commandMetadata['gdrive'] ?? null;

        if (is_array($gdrive) && ! empty($gdrive['failed'])) {
            $problems[] = sprintf(
                '%d file(s) failed to reach Google Drive; the local copies are intact.',
                is_array($gdrive['failed']) ? count($gdrive['failed']) : (int) $gdrive['failed'],
            );
        }

        if (is_string($commandMetadata['error_summary'] ?? null) && $commandMetadata['error_summary'] !== '') {
            $problems[] = $commandMetadata['error_summary'];
        }

        return $problems === [] ? null : implode(' ', $problems);
    }

    /**
     * @param  array{skip_reason?: ?string, exit_code?: ?int, output?: ?string, error?: ?string, metadata?: array<string, mixed>, duration_ms?: ?int}  $attributes
     */
    private function finish(
        ScheduledTask $task,
        ScheduledTaskRun $run,
        string $status,
        array $attributes = [],
    ): ScheduledTaskRun {
        $finishedAt = Carbon::now();

        $run->forceFill([
            'status' => $status,
            'skip_reason' => $attributes['skip_reason'] ?? null,
            'exit_code' => $attributes['exit_code'] ?? null,
            'output' => $this->sanitizer->sanitize($attributes['output'] ?? null),
            'error' => $this->truncateError($attributes['error'] ?? null),
            'metadata' => $attributes['metadata'] ?? null,
            'finished_at' => $finishedAt,
            'duration_ms' => $attributes['duration_ms']
                ?? ($run->started_at ? max(0, $finishedAt->diffInMilliseconds($run->started_at)) : null),
        ])->save();

        $stamp = match ($status) {
            ScheduledTaskRun::STATUS_SUCCESS => ['last_success_at' => $finishedAt],
            ScheduledTaskRun::STATUS_FAILED, ScheduledTaskRun::STATUS_BLOCKED_TOKEN => ['last_failure_at' => $finishedAt],
            default => [],
        };

        if ($stamp !== []) {
            $task->forceFill($stamp)->save();
        }

        Log::info('automation.task.finished', [
            'task_id' => $task->id,
            'slug' => $task->slug,
            'run_id' => $run->id,
            'status' => $status,
            'skip_reason' => $run->skip_reason,
            'exit_code' => $run->exit_code,
            'duration_ms' => $run->duration_ms,
        ]);

        return $run->refresh();
    }

    private function truncateError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        // `error` is a TEXT column and is rendered inline in the UI, so it is
        // held to a much tighter bound than the captured output.
        return $this->sanitizer->truncate($this->sanitizer->redact($error), 2000);
    }

    /**
     * Everything about the token that is safe to persist next to a run.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function publicTokenFacts(array $status): array
    {
        return [
            'status' => $status['status'] ?? null,
            'source' => $status['source'] ?? null,
            'fingerprint' => $status['fingerprint'] ?? null,
            'expires_at' => $status['expires_at'] ?? null,
        ];
    }

    /**
     * @param  array{ok: bool, status: array<string, mixed>, reason: ?string, message: ?string}  $preflight
     */
    private function raiseTokenAlert(array $preflight): void
    {
        $this->alerts->raise(
            AutomationAlert::TYPE_STOCKBIT_TOKEN,
            'renewal-required',
            AutomationAlert::SEVERITY_CRITICAL,
            'Stockbit token needs renewing',
            (string) ($preflight['message'] ?? 'The Stockbit token is unusable.'),
            $this->publicTokenFacts($preflight['status']),
        );
    }

    /**
     * Wait for a lock without holding a thread hostage forever.
     *
     * `block()` throws when the timeout expires, which is a normal outcome
     * here -- the weekly job waiting behind a long daily scrape -- so it is
     * translated into a boolean rather than an exception.
     */
    private function acquireWithWait(Lock $lock, int $seconds): bool
    {
        if ($seconds <= 0) {
            return $lock->get();
        }

        try {
            return $lock->block($seconds, static fn (): bool => true) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            // A lock that already expired cannot be released, which is not
            // worth failing a completed run over.
            Log::debug('automation.lock.release_failed', ['message' => $exception->getMessage()]);
        }
    }
}

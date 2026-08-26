<?php

namespace App\Console\Commands;

use App\Services\BackupStatus;
use App\Services\ContentHasher;
use App\Services\GoogleDriveHealth;
use App\Services\GoogleDriveOAuthClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Times each stage of what /dashboard/backups does, so a "504" or a
 * "Failed to fetch" in the browser can be traced to the call that is slow.
 *
 * gdrive:check answers "does Drive work at all". This answers "why is it
 * taking so long", which is a different question: every stage here succeeds in
 * the failing case, just not quickly enough for the gateway.
 */
class GoogleDriveDiagnoseCommand extends Command
{
    protected $signature = 'gdrive:diagnose
        {--disk=gdrive : Disk to measure}
        {--timeout=60 : Gateway timeout in seconds to judge the result against}';

    protected $description = 'Time each Google Drive call the backup status page makes, and say which one is slow.';

    /**
     * Stage timings, in milliseconds.
     *
     * @var array<int, array{label:string, ms:float, detail:string, ok:bool}>
     */
    private array $stages = [];

    public function handle(
        GoogleDriveHealth $health,
        ContentHasher $hasher,
        BackupStatus $status,
        GoogleDriveOAuthClassifier $classifier,
    ): int {
        $diskName = (string) $this->option('disk');
        $timeout = max(1, (int) $this->option('timeout'));

        $this->line("Disk: {$diskName}");
        $this->newLine();

        $historicalDir = trim((string) config('csv.mirror_path', 'seeds/historical'), '/');
        $brokerDir = trim((string) config('stockbit.save_dir', 'broker_summary'), '/');
        $seedDir = rtrim((string) config('csv.seed_dir'), '/');

        // 1. Resolving the disk performs the OAuth refresh-token exchange, so a
        //    slow network to Google shows up here before anything else.
        $verdict = $this->stage('resolve disk (OAuth exchange)', function () use ($health, $diskName) {
            $result = $health->check($diskName);

            return [$result['can_read'], $result['status']];
        }, fatalOnFailure: true);

        if ($verdict === false) {
            $this->render($timeout);
            $this->newLine();
            $this->error('Drive could not be reached at all. Run php artisan gdrive:check for the cause.');

            return self::FAILURE;
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            $this->error('Could not resolve the disk: '.$classifier->classify($e->getMessage())['message']);

            return self::FAILURE;
        }

        // 2. One listing per folder. This is the call that used to be made once
        //    plus twice more per file for size and mtime.
        $remoteFiles = 0;

        $this->stage("list {$historicalDir}", function () use ($disk, $historicalDir, &$remoteFiles) {
            foreach ($disk->getDriver()->listContents($historicalDir, false) as $item) {
                if ($item->isFile()) {
                    $remoteFiles++;
                }
            }

            return [true, "{$remoteFiles} files"];
        });

        // 3. Every checksum in the folder, in one query. Short of the file
        //    count means the fast path is not covering everything and the rest
        //    are downloaded in full to be hashed -- the difference between
        //    seconds and minutes. An empty folder legitimately yields none.
        $this->checksumStage($hasher, $disk, $historicalDir, $remoteFiles);

        // The report compares both collections, so both are measured. Only the
        // historical folder used to be checked here, which would have hidden a
        // fast path working for one directory and not the other.
        $brokerFiles = 0;

        $this->stage("list {$brokerDir}", function () use ($disk, $brokerDir, &$brokerFiles) {
            foreach ($disk->getDriver()->listContents($brokerDir, false) as $item) {
                if ($item->isFile()) {
                    $brokerFiles++;
                }
            }

            return [true, "{$brokerFiles} files"];
        });

        $this->checksumStage($hasher, $disk, $brokerDir, $brokerFiles);

        $this->stage('hash local seed CSVs', function () use ($hasher, $seedDir) {
            $count = 0;

            foreach (glob($seedDir.'/*.csv') ?: [] as $path) {
                if ($hasher->local($path) !== null) {
                    $count++;
                }
            }

            return [true, "{$count} files"];
        });

        // 4. The whole thing, which is what the HTTP request actually costs.
        $this->stage('build the full backup report', function () use ($status, $diskName) {
            $report = $status->report($diskName);
            $files = 0;

            foreach ($report['collections'] as $collection) {
                $files += $collection['counts']['total'];
            }

            return [true, "{$files} files compared"];
        });

        $this->render($timeout);

        return self::SUCCESS;
    }

    /**
     * Measure the bulk checksum fast path for one directory.
     *
     * When it comes up short the reason is printed. A bare "0 of 52" cost real
     * time to diagnose: it was a path-resolution failure, indistinguishable
     * from an empty folder or a permissions problem without it.
     */
    private function checksumStage(
        ContentHasher $hasher,
        mixed $disk,
        string $directory,
        int &$fileCount,
    ): void {
        $this->stage("bulk checksums for {$directory}", function () use ($hasher, $disk, $directory, &$fileCount) {
            $checksums = count($hasher->directoryChecksums($disk, $directory));
            $ok = $fileCount === 0 || $checksums >= $fileCount;
            $detail = $fileCount === 0 ? 'nothing to check' : "{$checksums} of {$fileCount} files";

            if (! $ok && ($reason = $hasher->lastFailureReason()) !== null) {
                $detail .= " -- {$reason}";
            }

            return [$ok, $detail];
        });
    }

    /**
     * Run one stage, timing it and recording the outcome.
     *
     * @param  callable(): array{0: bool, 1: string}  $work
     * @param  bool  $fatalOnFailure  Whether failing here makes the timings meaningless.
     * @return bool Whether the stage succeeded.
     */
    private function stage(string $label, callable $work, bool $fatalOnFailure = false): bool
    {
        $started = microtime(true);

        try {
            [$ok, $detail] = $work();
        } catch (Throwable $e) {
            $this->stages[] = [
                'label' => $label,
                'ms' => (microtime(true) - $started) * 1000,
                'detail' => 'failed: '.$e->getMessage(),
                'ok' => false,
                'fatal' => true,
            ];

            return false;
        }

        $this->stages[] = [
            'label' => $label,
            'ms' => (microtime(true) - $started) * 1000,
            'detail' => (string) $detail,
            'ok' => (bool) $ok,
            // A stage that completed but reported a shortfall is degraded, not
            // broken -- and the timing is more interesting in that case, not
            // less, because the slow path is what it just measured. Failing to
            // reach Drive at all is different: nothing after it means anything.
            'fatal' => ! $ok && $fatalOnFailure,
        ];

        return (bool) $ok;
    }

    private function render(int $timeout): void
    {
        $total = 0.0;

        foreach ($this->stages as $index => $stage) {
            $total += $stage['ms'];

            $this->line(sprintf(
                '  %d. %s %s %8s   %s',
                $index + 1,
                str_pad($stage['label'], 34, '.'),
                $stage['ok'] ? '  ok' : 'FAIL',
                $this->duration($stage['ms']),
                $stage['detail'],
            ));
        }

        $fatal = array_filter($this->stages, static fn (array $stage): bool => $stage['fatal'] ?? false);
        $degraded = array_filter(
            $this->stages,
            static fn (array $stage): bool => ! $stage['ok'] && ! ($stage['fatal'] ?? false),
        );

        $this->newLine();

        // A timing verdict on a run that never reached Drive would be a
        // reassuring "well within the timeout" for a page that cannot load at
        // all, so there is nothing to say about speed here.
        if ($fatal !== []) {
            $this->error('  A stage failed outright, so there is no meaningful timing to report.');

            return;
        }

        if ($degraded !== []) {
            $this->warn('  The bulk checksum path did not cover every file, so the rest were');
            $this->warn('  downloaded in full to hash them. That is the slow path, and the timing');
            $this->warn('  below reflects it.');
            $this->newLine();
        }

        // The report stage repeats the earlier work, so the figure that matters
        // for a gateway timeout is that stage alone, not the sum.
        $report = end($this->stages);
        $requestMs = is_array($report) ? $report['ms'] : $total;

        $this->line('  A page load costs the last line: '.$this->duration($requestMs));
        $this->newLine();

        $budget = $timeout * 1000;

        if ($requestMs < $budget * 0.5) {
            $this->info("  Comfortably inside a {$timeout}s gateway timeout.");

            return;
        }

        if ($requestMs < $budget) {
            $this->warn("  Inside a {$timeout}s timeout, but with little margin. Growth in file count will break it.");

            return;
        }

        $this->error("  Over the {$timeout}s gateway timeout -- this is what returns 504.");
        $this->newLine();
        $this->line('  If the bulk checksum line above reported 0 checksums, the fast path is not');
        $this->line('  working and every file is being downloaded to hash it. That is the cause.');
        $this->line('  Otherwise raise fastcgi_read_timeout in nginx and request_terminate_timeout');
        $this->line('  in the PHP-FPM pool, or lower the file count in the mirror folder.');
    }

    private function duration(float $ms): string
    {
        return $ms >= 1000
            ? number_format($ms / 1000, 2).' s'
            : number_format($ms, 0).' ms';
    }
}

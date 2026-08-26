<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Services\BackupStatus;
use App\Services\BarCsvMirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BackupStatusController extends ApiController
{
    /**
     * Seconds a report is reused for. Comparing content against Drive costs a
     * metadata call per file, so a page that several tabs poll should not
     * re-scan every time -- but a stale report that still shows a file as
     * pending after it was pushed is worse than the calls saved, so `fresh=1`
     * and every successful push bypass this.
     */
    private const CACHE_SECONDS = 45;

    private const CACHE_KEY = 'backup-status:report';

    /**
     * Longest a single push may hold the lock.
     */
    private const LOCK_SECONDS = 300;

    public function index(Request $request, BackupStatus $status)
    {
        $fresh = $request->boolean('fresh');

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return ApiResponse::success($this->report($status, $fresh));
    }

    /**
     * Push local historical CSVs to the Drive mirror.
     *
     * The client names symbols, never paths. Anything it sends is intersected
     * with the symbols this report actually marked pushable, so a request
     * cannot reach a file the page did not offer -- and cannot reach outside
     * the seed directory at all.
     */
    public function mirrorPush(Request $request, BackupStatus $status, BarCsvMirror $mirror)
    {
        $validated = $request->validate([
            'collection' => ['sometimes', 'string', 'in:historical'],
            'symbols' => ['sometimes', 'array', 'max:500'],
            'symbols.*' => ['string', 'regex:/^[A-Za-z0-9._-]{1,32}$/'],
        ]);

        $lock = Cache::lock('backup-status:mirror-push', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return ApiResponse::error('A Google Drive mirror push is already in progress.', 409);
        }

        try {
            $report = $this->report($status, true);
            $historical = $this->collection($report, 'historical');

            if ($historical === null) {
                return ApiResponse::error('The historical collection is unavailable.', 503);
            }

            $drive = $report['google_drive'] ?? [];

            if (($drive['can_read'] ?? false) !== true) {
                return ApiResponse::error(
                    'Google Drive is not available, so a push cannot be verified. '.
                    ($drive['message'] ?? ''),
                    503,
                );
            }

            $eligible = $this->symbolsFor($status->pushableNames($historical));
            $requested = $this->symbolsFor($validated['symbols'] ?? []);

            // No selection means "everything the page offered". A selection is
            // narrowed to that same set rather than trusted.
            $targets = $requested === [] ? $eligible : array_values(array_intersect($eligible, $requested));

            $rejected = array_values(array_diff($requested, $eligible));

            if ($targets === []) {
                return ApiResponse::success([
                    'uploaded' => [],
                    'skipped' => [],
                    'failed' => [],
                    'rejected' => $rejected,
                    'report' => $report,
                ], $rejected === []
                    ? 'Everything is already in sync; nothing to push.'
                    : 'None of the requested symbols need pushing.');
            }

            // force: the manifest records what was last sent, not what Drive
            // holds now. A file deleted or edited on Drive still matches the
            // manifest, and an unforced flush would skip it and report success.
            $result = $mirror->flush($targets, 'gdrive', force: true);

            return ApiResponse::success([
                'uploaded' => $result['uploaded'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'rejected' => $rejected,
                'report' => $this->report($status, true),
            ], $this->summarize($result));
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function report(BackupStatus $status, bool $fresh): array
    {
        if ($fresh) {
            $report = $status->report();
            Cache::put(self::CACHE_KEY, $report, self::CACHE_SECONDS);

            return $report;
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static fn () => $status->report());
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function collection(array $report, string $key): ?array
    {
        foreach ($report['collections'] ?? [] as $collection) {
            if (($collection['key'] ?? null) === $key) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Filenames as BarCsvMirror understands them: bare uppercase symbols.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function symbolsFor(array $names): array
    {
        $symbols = [];

        foreach ($names as $name) {
            $symbol = strtoupper(trim(pathinfo((string) $name, PATHINFO_FILENAME)));

            if ($symbol !== '') {
                $symbols[$symbol] = $symbol;
            }
        }

        $symbols = array_values($symbols);
        sort($symbols);

        return $symbols;
    }

    /**
     * @param  array{uploaded: array<int, string>, skipped: array<int, string>, failed: array<int, string>}  $result
     */
    private function summarize(array $result): string
    {
        $summary = sprintf(
            'Uploaded %d, skipped %d, failed %d.',
            count($result['uploaded']),
            count($result['skipped']),
            count($result['failed']),
        );

        if ($result['failed'] !== []) {
            $summary .= ' Failed: '.implode(', ', $result['failed']).'.';
        }

        return $summary;
    }
}

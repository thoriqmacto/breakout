<?php

namespace App\Console\Commands\Reconciliation;

use App\Services\Reconciliation\ReconciliationReadiness;
use App\Services\Reconciliation\ReconciliationStore;
use Illuminate\Console\Command;

/**
 * Report what the Backups dashboard reads, from the terminal.
 *
 * The reconciliation panels are built entirely from one manifest, and when
 * that manifest cannot be read they render as empty. Empty is the same
 * picture for several unrelated causes -- the file was never written, it was
 * written somewhere else, it exists but the web user cannot open it, or it
 * parsed as nothing -- and the page cannot tell them apart, because from
 * inside the request they all arrive as an absent manifest.
 *
 * From a terminal they are trivially distinguishable, which is the whole
 * point of this command. Run it as the user the web server runs as and it
 * answers the question the dashboard cannot:
 *
 *     sudo -u www-data php artisan data:reconcile-status
 *
 * It resolves the manifest through the same store and the same readiness
 * service the API uses, so its verdict cannot drift from the dashboard's.
 * What it adds is everything underneath: the resolved disk root, the absolute
 * path, and who owns the file -- because a manifest the scheduler wrote as
 * one user and the web server reads as another is a permissions fault that
 * looks exactly like a missing file.
 */
class DataReconcileStatusCommand extends Command
{
    protected $signature = 'data:reconcile-status
        {--json : Emit the readiness report as JSON}';

    protected $description = 'Report the reconciliation manifest and readiness the Backups dashboard reads.';

    public function handle(ReconciliationStore $store, ReconciliationReadiness $readiness): int
    {
        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->identity($store);
        $this->newLine();
        $this->manifest($report['reconciliation'] ?? [], $store);
        $this->newLine();
        $this->flow($report['flow_snapshot'] ?? []);
        $this->newLine();

        return $this->verdict($report['readiness'] ?? []);
    }

    private function identity(ReconciliationStore $store): void
    {
        $disk = $store->disk();
        $root = method_exists($disk, 'path') ? $disk->path('') : '(not a local disk)';

        $this->components->twoColumnDetail('<fg=gray>running as</>', $this->currentUser());
        $this->components->twoColumnDetail('<fg=gray>local disk</>', (string) config('reconciliation.local_disk', 'local'));

        // Paths go on their own line rather than through twoColumnDetail,
        // which pads to the terminal width and truncates the left of a long
        // value -- losing exactly the part that says which directory this is.
        $this->line('  <fg=gray>disk root</>   '.$root);
    }

    private function manifest(array $reconciliation, ReconciliationStore $store): void
    {
        $path = $store->manifestPath();
        $present = (bool) ($reconciliation['present'] ?? false);
        $assetCount = (int) ($reconciliation['asset_count'] ?? 0);

        $this->components->twoColumnDetail(
            '<options=bold>manifest</>',
            $present ? '<fg=green>present</>' : '<fg=red>absent or unreadable</>',
        );
        $this->line('  <fg=gray>path</>        '.$path);
        $this->line('  <fg=gray>absolute</>    '.($this->absolutePath($store, $path) ?? '—'));
        $this->line('  <fg=gray>ownership</>   '.$this->ownership($store, $path));
        $this->components->twoColumnDetail('  size', $this->bytes($store->size($path)));
        // line(), not twoColumnDetail: this message is a sentence, and the
        // column formatter truncates to the terminal width -- which silently
        // ate the half that names the user who cannot write.
        $this->line('  <fg=gray>directory</>   '.$this->directoryState($store));
        $this->components->twoColumnDetail('  generated at', (string) ($reconciliation['generated_at'] ?? '—'));
        $this->components->twoColumnDetail('  market date', (string) ($reconciliation['market_date'] ?? '—'));

        $this->components->twoColumnDetail(
            '  assets described',
            $assetCount === 0 ? '<fg=red>0</>' : (string) $assetCount,
        );

        // The documents are what a restore actually reads; the manifest only
        // indexes them. A manifest describing assets whose documents are gone
        // is a recovery that would not complete, and it reads as healthy from
        // the manifest alone.
        $stored = count($store->storedSymbols());
        $this->components->twoColumnDetail(
            '  documents on disk',
            $stored === $assetCount ? (string) $stored : sprintf('<fg=yellow>%d</> (manifest says %d)', $stored, $assetCount),
        );

        $this->components->twoColumnDetail('  healthy / warning / error', sprintf(
            '%d / %d / %d',
            (int) ($reconciliation['healthy'] ?? 0),
            (int) ($reconciliation['warning'] ?? 0),
            (int) ($reconciliation['error'] ?? 0),
        ));
        $this->components->twoColumnDetail('  latest OHLCV', (string) ($reconciliation['latest_ohlcv_date'] ?? '—'));
        $this->components->twoColumnDetail('  latest broker daily', (string) ($reconciliation['latest_broker_daily_date'] ?? '—'));
    }

    private function flow(array $snapshot): void
    {
        $ranked = (int) ($snapshot['ranked_count'] ?? 0);

        $this->components->twoColumnDetail(
            '<options=bold>flow snapshot</>',
            $ranked === 0 ? '<fg=red>nothing ranked</>' : sprintf('%d ranked', $ranked),
        );
        $this->components->twoColumnDetail('  window', sprintf('%d sessions', (int) ($snapshot['window'] ?? 0)));
        $this->components->twoColumnDetail('  accumulating', (string) count($snapshot['accumulating'] ?? []));
        $this->components->twoColumnDetail('  distributing', (string) count($snapshot['distributing'] ?? []));
        $this->components->twoColumnDetail(
            '  insufficient history',
            (string) (int) ($snapshot['insufficient_count'] ?? 0),
        );
    }

    /**
     * The dashboard banner's own verdict, and a non-zero exit when it is not
     * ready -- so this is usable as a check rather than only as a readout.
     */
    private function verdict(array $readiness): int
    {
        $status = (string) ($readiness['status'] ?? 'not_ready');

        match ($status) {
            'ready' => $this->components->info('Recovery layer is ready.'),
            'degraded' => $this->components->warn('Recovery layer is degraded.'),
            default => $this->components->error('Recovery layer is not ready.'),
        };

        foreach ((array) ($readiness['blockers'] ?? []) as $blocker) {
            $this->components->bulletList([$blocker]);
        }

        foreach ((array) ($readiness['warnings'] ?? []) as $warning) {
            $this->components->bulletList([$warning]);
        }

        return $status === 'not_ready' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether a rebuild could even write here.
     *
     * Reporting only on the manifest answers half the question. A missing
     * manifest and a manifest that cannot be created look identical from the
     * dashboard and from the first half of this readout, and they need
     * opposite responses: one is "run the rebuild", the other is "the rebuild
     * will fail until this directory is writable". The rebuild failing is how
     * that was found the slow way.
     */
    private function directoryState(ReconciliationStore $store): string
    {
        $absolute = $this->absolutePath($store, $store->root());

        if ($absolute === null) {
            return '—';
        }

        if (! is_dir($absolute)) {
            $parent = dirname($absolute);

            return is_writable($parent)
                ? '<fg=yellow>does not exist yet</> (creatable)'
                : sprintf('<fg=red>does not exist and %s is not writable by %s</>', $parent, $this->currentUser());
        }

        return is_writable($absolute)
            ? '<fg=green>writable</>'
            : sprintf('<fg=red>NOT writable by %s</> — a rebuild cannot write here', $this->currentUser());
    }

    private function absolutePath(ReconciliationStore $store, string $path): ?string
    {
        $disk = $store->disk();

        return method_exists($disk, 'path') ? $disk->path($path) : null;
    }

    /**
     * Who owns the manifest and what it is chmod'ed to.
     *
     * The reason this command exists in the shape it does. A scheduler
     * running as one user writes a file the web server, running as another,
     * cannot open -- and every layer above reports an absent manifest, which
     * is indistinguishable from one that was never built.
     */
    private function ownership(ReconciliationStore $store, string $path): string
    {
        $absolute = $this->absolutePath($store, $path);

        if ($absolute === null || ! is_file($absolute)) {
            return '—';
        }

        $mode = @fileperms($absolute);
        $readable = is_readable($absolute);

        return sprintf(
            '%s:%s %s%s',
            $this->userName(@fileowner($absolute)),
            $this->groupName(@filegroup($absolute)),
            $mode === false ? '????' : substr(sprintf('%o', $mode), -4),
            $readable ? '' : ' <fg=red>(not readable by '.$this->currentUser().')</>',
        );
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    private function userName(int|false $uid): string
    {
        if ($uid === false) {
            return '?';
        }

        if (function_exists('posix_getpwuid')) {
            $user = posix_getpwuid($uid);

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return (string) $uid;
    }

    private function groupName(int|false $gid): string
    {
        if ($gid === false) {
            return '?';
        }

        if (function_exists('posix_getgrgid')) {
            $group = posix_getgrgid($gid);

            if (is_array($group) && isset($group['name'])) {
                return (string) $group['name'];
            }
        }

        return (string) $gid;
    }

    private function bytes(?int $size): string
    {
        return $size === null ? '—' : number_format($size).' bytes';
    }
}

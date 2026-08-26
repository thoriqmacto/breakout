<?php

namespace App\Console\Commands;

use App\Models\BandarDetectorSummary;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Services\BrokerSummaryImporter;
use Illuminate\Console\Command;

/**
 * Rebuild canonical broker-summary windows from the archived JSON.
 *
 * Rows imported before the window model existed carry only the range *start*,
 * stamped onto broker_summary_facts.trade_date. Their true end date cannot be
 * inferred from the database -- assuming to_date = trade_date would swap one
 * wrong assumption for another -- but the archived responses still hold
 * data.from and data.to, so the correct ranges can be recovered from them.
 *
 * Safe to run repeatedly: the importer keys a window on
 * (asset, from_date, to_date, transaction_type) and replaces its entries.
 */
class BrokerSummaryRebuildCommand extends Command
{
    protected $signature = 'broker-summary:rebuild
        {--disk= : Disk holding the archived JSON; defaults to stockbit.save_disk}
        {--dir= : Directory within that disk; defaults to stockbit.save_dir}
        {--dry-run : Report what would be rebuilt without writing}';

    protected $description = 'Rebuild broker-summary windows from archived Stockbit JSON.';

    public function handle(BrokerSummaryImporter $importer): int
    {
        $disk = $this->option('disk') ?: null;
        $dir = $this->option('dir') ?: null;

        $before = [
            'windows' => BrokerSummaryWindow::count(),
            'entries' => BrokerSummaryEntry::count(),
        ];

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing will be written.');
            $this->line(sprintf('  windows currently stored: %d', $before['windows']));
            $this->line(sprintf('  entries currently stored: %d', $before['entries']));
            $this->newLine();
            $this->line('Re-run without --dry-run to rebuild from the archived JSON.');

            return self::SUCCESS;
        }

        $this->line('Rebuilding broker-summary windows from archived JSON...');

        $result = $importer->importFromDisk($disk, $dir);

        $after = [
            'windows' => BrokerSummaryWindow::count(),
            'entries' => BrokerSummaryEntry::count(),
        ];

        $this->newLine();
        $this->line(sprintf('  files read ......... %d', $result['file_count']));
        $this->line(sprintf('  broker rows ........ %d', $result['row_count']));
        $this->line(sprintf(
            '  windows ............ %d (was %d)',
            $after['windows'],
            $before['windows'],
        ));
        $this->line(sprintf(
            '  entries ............ %d (was %d)',
            $after['entries'],
            $before['entries'],
        ));

        // A window whose range could not be resolved is reported rather than
        // invented; the importer logs the file it came from.
        $unlinked = BandarDetectorSummary::whereNull('broker_summary_window_id')->count();

        if ($unlinked > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d bandar detector row(s) are still not linked to a window.',
                $unlinked,
            ));
            $this->line('Those predate the window model and their archived JSON is no longer on disk.');
            $this->line('Their true range cannot be recovered, so they are left as they are.');
        }

        $this->newLine();
        $this->info('Rebuild complete. Re-running this command is safe.');

        return self::SUCCESS;
    }
}

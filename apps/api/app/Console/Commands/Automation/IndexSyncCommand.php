<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\RunMetadata;
use App\Services\Indexes\IndexCatalogReader;
use App\Services\Indexes\IndexCatalogReadException;
use App\Services\Indexes\IndexMembershipSync;
use App\Services\Indexes\IndexRegistry;
use App\Services\Indexes\IndexSyncResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keep a published index's membership current.
 *
 * Two sources, one destination. Left alone it reads the catalogue page with a
 * headless browser; given --symbols or --file it takes the list from there
 * instead. Both end in IndexMembershipSync, so a pasted list and a scraped one
 * are dated, guarded and recorded identically -- and a page whose markup
 * changes does not leave the feature with no way to be updated.
 *
 * Scheduled daily rather than twice a year, which is how often the exchange
 * actually reviews an index. The point is not to catch a review the day it
 * happens but to notice that the page is still readable: a reader that has
 * quietly stopped working is indistinguishable from an index that has not
 * changed, and the only difference is how long it takes to find out.
 */
class IndexSyncCommand extends Command
{
    protected $signature = 'automation:index-sync
        {--index= : Index code to sync (default: the configured default)}
        {--symbols= : Use this list instead of reading the page (comma or space separated)}
        {--file= : Read the list from a file instead of reading the page}
        {--force : Apply a list the safety guards would otherwise refuse}
        {--dry-run : Report what would change without writing it}
        {--diagnose : Print what the catalogue page actually contained, for when a read finds nothing}
        {--dump-html= : Write the rendered page to this path, for when the markup needs reading}';

    protected $description = 'Refresh a published index\'s membership from its catalogue page, or from a supplied list.';

    private const ALERT_TYPE = AutomationAlert::TYPE_INDEX_MEMBERSHIP;

    public function handle(
        IndexRegistry $registry,
        IndexCatalogReader $reader,
        IndexMembershipSync $sync,
        AutomationAlerts $alerts,
        RunMetadata $metadata,
    ): int {
        $code = $this->resolveCode($registry);

        if ($code === null) {
            return self::INVALID;
        }

        $definition = $registry->require($code);
        $dryRun = (bool) $this->option('dry-run');

        $metadata->merge([
            'job' => 'index_sync',
            'index' => $code,
            'dry_run' => $dryRun,
        ]);

        $supplied = $this->suppliedSymbols();

        if ($supplied !== null && $supplied['symbols'] === []) {
            $this->error($supplied['error'] ?? 'No symbols were supplied.');

            return self::INVALID;
        }

        if ($supplied !== null) {
            $symbols = $supplied['symbols'];
            $source = $supplied['source'];
            $metadata->merge(['source' => $source, 'received' => count($symbols)]);
        } else {
            $read = $this->readCatalog($reader, $definition, $code, $alerts, $metadata);

            if ($read === null) {
                return self::FAILURE;
            }

            $symbols = $read;
            $source = 'browser';
        }

        $result = $sync->apply($code, $symbols, $source, null, (bool) $this->option('force'), $dryRun);

        $metadata->merge($result->toArray());

        return $this->report($result, $definition, $alerts, $dryRun);
    }

    private function resolveCode(IndexRegistry $registry): ?string
    {
        $option = $this->option('index');
        $code = is_string($option) && trim($option) !== ''
            ? $registry->normaliseCode($option)
            : $registry->defaultCode();

        if ($code === '' || ! $registry->has($code)) {
            $this->error(sprintf(
                'Unknown index "%s". Configured: %s.',
                $code === '' ? '(none)' : $code,
                $registry->codes() === [] ? 'none' : implode(', ', $registry->codes()),
            ));

            return null;
        }

        return $code;
    }

    /**
     * A list handed in rather than read, or null when the page is the source.
     *
     * @return array{symbols: array<int, string>, source: string, error?: string}|null
     */
    private function suppliedSymbols(): ?array
    {
        $inline = $this->option('symbols');

        if (is_string($inline) && trim($inline) !== '') {
            return ['symbols' => $this->split($inline), 'source' => 'manual'];
        }

        $path = $this->option('file');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (! File::isFile($path) || ! File::isReadable($path)) {
            return ['symbols' => [], 'source' => 'file', 'error' => sprintf('No readable file at %s.', $path)];
        }

        return ['symbols' => $this->split(File::get($path)), 'source' => 'file'];
    }

    /**
     * @return array<int, string>
     */
    private function split(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,;|]+/', $raw) ?: []),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>|null
     */
    private function readCatalog(
        IndexCatalogReader $reader,
        array $definition,
        string $code,
        AutomationAlerts $alerts,
        RunMetadata $metadata,
    ): ?array {
        $url = (string) ($definition['url'] ?? '');

        if ($url === '') {
            $this->error(sprintf('No catalogue URL is configured for %s.', $code));

            return null;
        }

        $metadata->merge(['source' => 'browser', 'source_url' => $url]);

        $options = [
            'diagnose' => (bool) $this->option('diagnose'),
            'dump_html' => is_string($this->option('dump-html')) && trim((string) $this->option('dump-html')) !== ''
                ? trim((string) $this->option('dump-html'))
                : null,
        ];

        try {
            ['symbols' => $symbols, 'evidence' => $evidence] = $reader->read($url, $options);
        } catch (IndexCatalogReadException $exception) {
            // The evidence is counts and a page title, never page content: a
            // catalogue page is public, but a run record is not the place to
            // accumulate somebody else's markup.
            $metadata->merge([
                'read_failed' => true,
                'read_reason' => $exception->reason,
                'error_summary' => $exception->getMessage(),
                'evidence' => $this->countsOnly($exception->evidence),
            ]);

            $alerts->raise(
                self::ALERT_TYPE,
                $code,
                $exception->needsAttention() ? AutomationAlert::SEVERITY_WARNING : AutomationAlert::SEVERITY_INFO,
                sprintf('%s membership could not be refreshed', $code),
                sprintf(
                    '%s Membership is unchanged from the last successful read, so the badges on the Assets page are '
                    .'as old as that. Paste the list from %s into the index panel, or run '
                    .'"php artisan automation:index-sync --index=%s --symbols=..." to update it by hand.',
                    $exception->getMessage(),
                    $url,
                    $code,
                ),
                ['reason' => $exception->reason, 'url' => $url],
            );

            $this->error($exception->getMessage());
            $this->reportDiagnosis($exception->evidence);

            return null;
        }

        $metadata->merge(['evidence' => $this->countsOnly($evidence), 'received' => count($symbols)]);

        $this->reportDiagnosis($evidence);

        $this->line(sprintf(
            '  Read %d ticker-shaped entries (%s links, %s data, %s cells).',
            count($symbols),
            $evidence['from_links'] ?? '?',
            $evidence['from_json'] ?? '?',
            $evidence['from_cells'] ?? '?',
        ));

        return $symbols;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function report(
        IndexSyncResult $result,
        array $definition,
        AutomationAlerts $alerts,
        bool $dryRun,
    ): int {
        if (! $result->accepted) {
            $alerts->raise(
                self::ALERT_TYPE,
                $result->code,
                AutomationAlert::SEVERITY_WARNING,
                sprintf('%s membership was not updated', $result->code),
                sprintf(
                    'The list was rejected because %s. Nothing was written, so the stored membership is the last '
                    .'one that passed. Re-run with --force if the change is real.',
                    (string) $result->refusedReason,
                ),
                ['reason' => $result->refusedReason, 'received' => $result->received],
            );

            $this->error($result->summary());

            return self::FAILURE;
        }

        $alerts->resolve(self::ALERT_TYPE, $result->code);

        $expected = (int) ($definition['expected_size'] ?? 0);

        $this->info(($dryRun ? '[dry run] ' : '').$result->summary());

        if ($expected > 0 && $result->memberCount !== $expected) {
            // Said, not enforced. An index between reviews genuinely holds
            // fewer names than its title claims, and padding the list to the
            // round number would be inventing constituents.
            $this->warn(sprintf(
                '  %d members read against an expected %d. That is normal between reviews, but worth a look if it keeps drifting.',
                $result->memberCount,
                $expected,
            ));
        }

        if ($result->rejected !== []) {
            $this->line('  Ignored as not ticker-shaped: '.implode(', ', array_slice($result->rejected, 0, 20)));
        }

        return self::SUCCESS;
    }

    /**
     * Show an operator what the page actually held.
     *
     * Only when --diagnose asked for it, and only to the terminal. A read that
     * finds nothing is a question about somebody else's markup, and the one
     * fact that settles it is whether ticker-shaped words are in the rendered
     * text at all: if they are, the selectors are wrong; if they are not, the
     * list never rendered and no selector would have helped.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function reportDiagnosis(array $evidence): void
    {
        if (isset($evidence['dump_html'])) {
            $this->line('  Rendered page written to '.$evidence['dump_html']);
        }

        $diagnosis = $evidence['diagnosis'] ?? null;

        if (! is_array($diagnosis)) {
            return;
        }

        $this->newLine();
        $this->line('<comment>What the page contained</comment>');
        $this->line('  Landed on : '.($diagnosis['url'] ?? '?'));
        $this->line('  Title     : '.($diagnosis['title'] ?? '(none)'));
        $this->line('  Text      : '.($diagnosis['text_length'] ?? 0).' chars');

        $counts = is_array($diagnosis['tag_counts'] ?? null) ? $diagnosis['tag_counts'] : [];

        $this->line('  Elements  : '.implode(', ', array_map(
            static fn (string $tag, $count): string => $tag.'='.$count,
            array_keys($counts),
            array_values($counts),
        )));

        $words = is_array($diagnosis['ticker_shaped_words'] ?? null) ? $diagnosis['ticker_shaped_words'] : [];

        $this->line('  Ticker-shaped words in the text: '.(
            $words === [] ? '(none -- the list never rendered)' : implode(' ', array_slice($words, 0, 40))
        ));

        $hrefs = is_array($diagnosis['href_samples'] ?? null) ? $diagnosis['href_samples'] : [];

        if ($hrefs !== []) {
            $this->line('  Link paths:');

            foreach (array_slice($hrefs, 0, 30) as $href) {
                $this->line('    '.$href);
            }
        }

        if (($diagnosis['text_head'] ?? '') !== '') {
            $this->line('  First words: '.$diagnosis['text_head']);
        }
    }

    /**
     * Counts and identifiers only, for the run record.
     *
     * The diagnosis is samples of a third party's page. It belongs on the
     * terminal of whoever asked for it, not accumulating in a table the
     * dashboard reads.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function countsOnly(array $evidence): array
    {
        unset($evidence['diagnosis']);

        return $evidence;
    }
}

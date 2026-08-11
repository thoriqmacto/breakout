<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies row data between two configured connections, table by table.
 *
 * Written for the SQLite -> MariaDB move. Dumping SQLite and replaying the SQL
 * does not work: `.dump` emits AUTOINCREMENT, double-quoted identifiers and
 * SQLite's own type names, none of which MariaDB accepts. Reading through the
 * query builder sidesteps the dialect entirely.
 *
 * The destination schema must already exist -- run `migrate` against it first.
 * This command only moves rows.
 */
class DbCopyCommand extends Command
{
    protected $signature = 'db:copy
        {--from=sqlite : Source connection name}
        {--to=mariadb : Destination connection name}
        {--chunk=500 : Rows to read and insert per batch}
        {--only= : Comma-separated table allowlist}
        {--truncate : Empty each destination table before copying into it}
        {--pretend : Report what would be copied without writing}';

    protected $description = 'Copy table data from one database connection to another.';

    /**
     * Tables that must not be copied. `migrations` is already populated by
     * running migrate against the destination, and copying it would duplicate
     * every row. The rest is transient state that regenerates on demand and is
     * usually stale by the time a cutover happens.
     */
    private const SKIP = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($from === $to) {
            $this->error('--from and --to must be different connections.');

            return self::FAILURE;
        }

        try {
            $source = DB::connection($from);
            $destination = DB::connection($to);
            $source->getPdo();
            $destination->getPdo();
        } catch (Throwable $e) {
            $this->error("Could not connect: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tables = $this->resolveTables($destination);

        if ($tables === []) {
            $this->error("No tables found on '{$to}'. Run `php artisan migrate` against it first.");

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $chunk = max(1, (int) $this->option('chunk'));

        // Row order across tables cannot satisfy every foreign key, so the
        // constraints come off for the duration and go back on in a finally.
        $this->toggleForeignKeys($destination, false);

        $totals = [];

        try {
            foreach ($tables as $table) {
                if (! $source->getSchemaBuilder()->hasTable($table)) {
                    $this->line("  <fg=gray>skip</> {$table} (absent on {$from})");

                    continue;
                }

                $count = $source->table($table)->count();

                if ($count === 0) {
                    $this->line("  <fg=gray>skip</> {$table} (empty)");

                    continue;
                }

                if ($pretend) {
                    $this->line("  <fg=yellow>would copy</> {$table}: {$count} rows");
                    $totals[$table] = $count;

                    continue;
                }

                if ($this->option('truncate')) {
                    $destination->table($table)->delete();
                }

                $copied = $this->copyTable($source, $destination, $table, $chunk);
                $totals[$table] = $copied;

                $status = $copied === $count ? 'fg=green' : 'fg=red';
                $this->line("  <{$status}>copied</> {$table}: {$copied}/{$count} rows");
            }
        } finally {
            $this->toggleForeignKeys($destination, true);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d rows across %d tables (%s -> %s).',
            $pretend ? 'Would copy' : 'Copied',
            array_sum($totals),
            count($totals),
            $from,
            $to,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTables(Connection $destination): array
    {
        $only = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('only')),
        )));

        if ($only !== []) {
            return $only;
        }

        $tables = array_map(
            fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t->name,
            $destination->getSchemaBuilder()->getTables(),
        );

        return array_values(array_diff(array_filter($tables), self::SKIP));
    }

    private function copyTable(
        Connection $source,
        Connection $destination,
        string $table,
        int $chunk,
    ): int {
        $copied = 0;
        $columns = $destination->getSchemaBuilder()->getColumnListing($table);

        // orderBy is required for chunk() to paginate deterministically. Not
        // every table has an `id`, so fall back to the first column.
        $key = in_array('id', $columns, true) ? 'id' : ($columns[0] ?? null);

        if ($key === null) {
            return 0;
        }

        $source->table($table)->orderBy($key)->chunk($chunk, function ($rows) use (
            $destination, $table, $columns, &$copied
        ) {
            $batch = [];

            foreach ($rows as $row) {
                // Drop any source column the destination does not have, so a
                // schema that has moved on does not abort the whole copy.
                $batch[] = array_intersect_key((array) $row, array_flip($columns));
            }

            if ($batch !== []) {
                $destination->table($table)->insert($batch);
                $copied += count($batch);
            }
        });

        return $copied;
    }

    private function toggleForeignKeys(Connection $connection, bool $on): void
    {
        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->statement('SET FOREIGN_KEY_CHECKS = '.($on ? '1' : '0')),
            'sqlite' => $connection->statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF')),
            'pgsql' => $connection->statement("SET session_replication_role = '".($on ? 'origin' : 'replica')."'"),
            default => null,
        };
    }
}

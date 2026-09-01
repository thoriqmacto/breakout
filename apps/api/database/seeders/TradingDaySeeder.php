<?php

namespace Database\Seeders;

use App\Services\TradingDayLedger;
use App\Services\TradingDayWriter;
use Illuminate\Database\Seeder;

/**
 * Restore the trading-day calendar from the checked-in ledger.
 *
 * The ledger can contain sessions whose close was unknown when it was last
 * written. Seeding used to upsert those straight over the column, which meant
 * `db:seed` could undo a Yahoo repair -- a session whose value had since been
 * recovered would go back to NULL because a file written weeks earlier still
 * said it was unknown.
 *
 * So the seeder writes through TradingDayWriter like every other path: it can
 * add sessions and it can fill in closes, and it cannot turn a known close
 * back into an unknown one. Reading is TradingDayLedger's job, so the file's
 * shape is parsed in exactly one place and the seeder and the build command
 * cannot drift on what it means.
 */
class TradingDaySeeder extends Seeder
{
    public function __construct(
        private readonly ?TradingDayWriter $writer = null,
        private readonly ?TradingDayLedger $ledger = null,
    ) {}

    public function run(): void
    {
        $ledger = $this->ledger ?? app(TradingDayLedger::class);

        if (! $ledger->exists()) {
            $this->command?->warn('Trading day seeder data file not found: '.$ledger->path());

            return;
        }

        $records = $ledger->read();

        if ($records === []) {
            $this->command?->warn('Trading day seeder data file has no usable records: '.$ledger->path());

            return;
        }

        $payload = [];

        foreach ($records as $date => $close) {
            $payload[] = ['date' => $date, 'close' => $close];
        }

        $writer = $this->writer ?? app(TradingDayWriter::class);
        $result = $writer->write($payload);

        $this->command?->info('Seeded '.count($payload).' trading day records.');

        if ($result['repaired'] !== []) {
            $this->command?->info('Filled in '.count($result['repaired']).' previously unknown close(s).');
        }

        if ($result['preserved'] !== []) {
            $this->command?->info(
                'Kept '.count($result['preserved']).' close(s) the seed file did not know; seeding never downgrades a known value.'
            );
        }
    }
}

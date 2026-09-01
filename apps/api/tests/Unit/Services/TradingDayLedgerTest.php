<?php

namespace Tests\Unit\Services;

use App\Services\TradingDayLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The ledger's half of the never-downgrade rule, asserted on the ledger itself.
 *
 * The table's copy of this guarantee lives in TradingDayWriter. Both are
 * needed: a close is only really safe when neither the database nor the file
 * can be talked into forgetting it.
 */
class TradingDayLedgerTest extends TestCase
{
    private string $root;

    private TradingDayLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/breakout-ledger-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/seeders/data');
        $this->app->useDatabasePath($this->root);

        $this->ledger = new TradingDayLedger;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeLedger(string $body): void
    {
        File::put($this->root.'/seeders/data/trading_days.php', "<?php\n\nreturn [\n".$body."\n];\n");
    }

    public function test_a_missing_file_reads_as_empty_rather_than_throwing(): void
    {
        $this->assertFalse($this->ledger->exists());
        $this->assertSame([], $this->ledger->read());
        $this->assertSame([], $this->ledger->knownCloses(['2026-08-28']));
    }

    public function test_it_distinguishes_an_unknown_close_from_an_unrecorded_session(): void
    {
        $this->writeLedger("    ['date' => '2026-08-28', 'close' => null],");

        $read = $this->ledger->read();

        $this->assertArrayHasKey('2026-08-28', $read);
        $this->assertNull($read['2026-08-28']);
        $this->assertArrayNotHasKey('2026-08-31', $read);
    }

    public function test_known_closes_omits_sessions_the_ledger_has_no_value_for(): void
    {
        $this->writeLedger(
            "    ['date' => '2026-08-28', 'close' => 6518.12109375],\n".
            "    ['date' => '2026-08-31', 'close' => null],"
        );

        $this->assertSame(['2026-08-28'], array_keys($this->ledger->knownCloses()));
        $this->assertSame([], $this->ledger->knownCloses(['2026-08-31']));
        $this->assertEqualsWithDelta(
            6518.12109375,
            $this->ledger->knownCloses(['2026-08-28', '2026-08-31'])['2026-08-28'],
            0.000001,
        );
    }

    public function test_sync_keeps_a_close_the_records_do_not_know(): void
    {
        $this->writeLedger("    ['date' => '2026-08-28', 'close' => 6518.12109375],");

        $result = $this->ledger->sync([['date' => '2026-08-28', 'close' => null]]);

        $this->assertSame(['2026-08-28'], $result['preserved']);
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->ledger->read()['2026-08-28'], 0.000001);
    }

    public function test_sync_fills_an_unknown_close_and_adds_new_sessions(): void
    {
        $this->writeLedger("    ['date' => '2026-08-28', 'close' => null],");

        $result = $this->ledger->sync([
            ['date' => '2026-08-28', 'close' => 6518.12109375],
            ['date' => '2026-08-31', 'close' => 6525.47802734375],
        ]);

        $this->assertSame(['2026-08-31'], $result['added']);
        $this->assertSame(['2026-08-28'], $result['filled']);
        $this->assertTrue($result['changed']);
        $this->assertSame(2, $result['total']);
    }

    public function test_sync_records_a_session_whose_close_is_not_yet_known(): void
    {
        $this->writeLedger("    ['date' => '2026-08-28', 'close' => 6518.12109375],");

        // A row with no close still establishes that the session happened.
        $this->ledger->sync([['date' => '2026-08-31', 'close' => null]]);

        $read = $this->ledger->read();

        $this->assertArrayHasKey('2026-08-31', $read);
        $this->assertNull($read['2026-08-31']);
    }

    public function test_sync_reports_no_change_when_the_file_already_matches(): void
    {
        $records = [['date' => '2026-08-28', 'close' => 6518.12109375]];

        $this->assertTrue($this->ledger->sync($records)['changed']);
        $this->assertFalse($this->ledger->sync($records)['changed']);
    }

    public function test_it_writes_dates_in_order_and_reads_its_own_output_back(): void
    {
        $this->ledger->sync([
            ['date' => '2026-08-31', 'close' => 6525.47802734375],
            ['date' => '2026-08-28', 'close' => 6518.12109375],
        ]);

        $this->assertSame(['2026-08-28', '2026-08-31'], array_keys($this->ledger->read()));
        $this->assertStringNotContainsString('NULL', File::get($this->ledger->path()));
    }

    public function test_it_normalises_date_spellings_and_numeric_strings(): void
    {
        $this->writeLedger("    ['date' => '2026-08-28 00:00:00', 'close' => '6518.12109375'],");

        $read = $this->ledger->read();

        $this->assertSame(['2026-08-28'], array_keys($read));
        $this->assertEqualsWithDelta(6518.12109375, $read['2026-08-28'], 0.000001);
    }

    public function test_a_duplicate_date_carrying_no_close_cannot_erase_the_one_that_did(): void
    {
        $this->writeLedger(
            "    ['date' => '2026-08-28', 'close' => 6518.12109375],\n".
            "    ['date' => '2026-08-28', 'close' => null],"
        );

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->ledger->read()['2026-08-28'], 0.000001);
    }

    public function test_the_path_follows_a_redirected_database_path(): void
    {
        $this->assertSame($this->root.'/seeders/data/trading_days.php', $this->ledger->path());
    }
}

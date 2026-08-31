<?php

namespace Tests\Unit\Services;

use App\Services\TradingDayWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The invariant, tested at the only place that can enforce it.
 *
 * Callers are not trusted to remember the rule, so it is asserted here on the
 * writer itself rather than only through the importers that use it.
 */
class TradingDayWriterTest extends TestCase
{
    use RefreshDatabase;

    private TradingDayWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(TradingDayWriter::class);
    }

    private function seedDay(string $date, ?float $close): void
    {
        DB::table('trading_days')->insert([
            'date' => $date, 'close' => $close, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function close(string $date): ?float
    {
        $value = DB::table('trading_days')->where('date', $date)->value('close');

        return $value === null ? null : (float) $value;
    }

    public function test_unknown_never_overwrites_known(): void
    {
        $this->seedDay('2026-08-28', 6518.12109375);

        $result = $this->writer->write([['date' => '2026-08-28', 'close' => null]]);

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        $this->assertSame(['2026-08-28'], $result['preserved']);
        $this->assertSame([], $result['repaired']);
    }

    public function test_known_always_replaces_unknown(): void
    {
        $this->seedDay('2026-08-28', null);

        $result = $this->writer->write([['date' => '2026-08-28', 'close' => 6518.12109375]]);

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        $this->assertSame(['2026-08-28'], $result['repaired']);
    }

    public function test_a_new_date_without_a_close_still_establishes_the_session(): void
    {
        $result = $this->writer->write([['date' => '2026-09-01', 'close' => null]]);

        $this->assertDatabaseHas('trading_days', ['date' => '2026-09-01', 'close' => null]);
        $this->assertSame(['2026-09-01'], $result['date_only']);
        // Nothing was overwritten, because nothing was there.
        $this->assertSame([], $result['preserved']);
    }

    public function test_within_one_batch_a_duplicate_date_without_a_close_cannot_erase_one_with(): void
    {
        $this->writer->write([
            ['date' => '2026-08-28', 'close' => 6518.12109375],
            ['date' => '2026-08-28', 'close' => null],
        ]);

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
    }

    public function test_dates_are_normalised_to_one_key_regardless_of_spelling(): void
    {
        $this->writer->write([['date' => '2026-08-28 00:00:00', 'close' => 6518.12109375]]);
        $this->writer->write([['date' => '2026-08-28', 'close' => 6519.0]]);

        $this->assertSame(1, DB::table('trading_days')->count());
        $this->assertSame('2026-08-28', DB::table('trading_days')->value('date'));
        $this->assertEqualsWithDelta(6519.0, (float) $this->close('2026-08-28'), 0.000001);
    }

    public function test_unusable_rows_are_dropped_rather_than_written(): void
    {
        $result = $this->writer->write([
            ['date' => null, 'close' => 1.0],
            ['date' => 'not a date', 'close' => 1.0],
            ['date' => '2026-08-28', 'close' => 'NaN'],
        ]);

        $this->assertSame(['2026-08-28'], $result['dates']);
        $this->assertNull($this->close('2026-08-28'));
    }

    public function test_incomplete_dates_lists_sessions_with_unknown_closes(): void
    {
        $this->seedDay('2026-08-27', 6521.75);
        $this->seedDay('2026-08-28', null);
        $this->seedDay('2026-08-31', 6525.47802734375);

        $this->assertSame(['2026-08-28'], $this->writer->incompleteDates('2026-08-27', '2026-08-31'));
        $this->assertSame([], $this->writer->incompleteDates('2026-08-29', '2026-08-31'));
    }

    public function test_existing_closes_distinguishes_a_missing_row_from_an_unknown_close(): void
    {
        $this->seedDay('2026-08-28', null);

        $existing = $this->writer->existingCloses(['2026-08-28', '2026-08-29']);

        // Present with null: the session is recorded, its close is not.
        $this->assertArrayHasKey('2026-08-28', $existing);
        $this->assertNull($existing['2026-08-28']);
        // Absent: no session recorded at all. A different fact.
        $this->assertArrayNotHasKey('2026-08-29', $existing);
    }
}

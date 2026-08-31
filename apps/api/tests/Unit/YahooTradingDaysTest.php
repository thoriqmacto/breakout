<?php

namespace Tests\Unit;

use App\Models\TradingDay;
use App\Services\PythonRunner;
use App\Services\TradingDayWriter;
use App\Services\YahooTradingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * The rule these all circle: a known close is stronger information than an
 * unknown one, and unknown must never overwrite known.
 *
 * Every test asserts against a fresh database read rather than the returned
 * collection, because the production failure happened *after* an upsert the
 * command had already reported as successful.
 */
class YahooTradingDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $tickerData
     */
    private function service(array $tickerData, ?callable $inspectArgs = null): YahooTradingDays
    {
        $runner = Mockery::mock(PythonRunner::class);
        $expectation = $runner->shouldReceive('run')->once();

        if ($inspectArgs !== null) {
            $expectation->withArgs(function (string $script, $payload, array $args) use ($inspectArgs) {
                $this->assertSame('get_stocks.py', $script);
                $this->assertNull($payload);
                $this->assertContains('--emit-dates', $args);
                $inspectArgs($args);

                return true;
            });
        }

        $expectation->andReturn([
            'ok' => true,
            'stderr' => '',
            'stdout' => '',
            'json' => ['tickers' => [$tickerData + ['ticker' => '^JKSE']]],
        ]);

        return new YahooTradingDays($runner, app(TradingDayWriter::class));
    }

    private function storedClose(string $date): ?float
    {
        $value = DB::table('trading_days')->where('date', $date)->value('close');

        return $value === null ? null : (float) $value;
    }

    private function seedRow(string $date, ?float $close): void
    {
        DB::table('trading_days')->insert([
            'date' => $date, 'close' => $close, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_numeric_close_is_inserted(): void
    {
        $service = $this->service([
            'entries' => [
                ['date' => '2024-01-02', 'close' => 123.456789],
                ['date' => '2024-01-03', 'close' => '125.5'],
            ],
        ]);

        $report = $service->import('2024-01-01', '2024-01-31');

        $this->assertSame(2, $report->providerSessions());
        $this->assertSame(2, $report->providerClosesCount());
        $this->assertEqualsWithDelta(123.456789, (float) $this->storedClose('2024-01-02'), 0.000001);
        $this->assertEqualsWithDelta(125.5, (float) $this->storedClose('2024-01-03'), 0.000001);
    }

    public function test_a_numeric_close_repairs_an_existing_null(): void
    {
        $this->seedRow('2026-08-28', null);

        $report = $this->service([
            'entries' => [['date' => '2026-08-28', 'close' => 6518.12109375]],
        ])->import('2026-08-28', '2026-08-31');

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
        $this->assertSame(['2026-08-28'], $report->repaired);
    }

    public function test_a_newer_numeric_close_updates_an_older_one(): void
    {
        $this->seedRow('2026-08-28', 6518.00);

        $this->service([
            'entries' => [['date' => '2026-08-28', 'close' => 6518.12109375]],
        ])->import('2026-08-28', '2026-08-31');

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
    }

    public function test_an_incoming_null_never_overwrites_a_stored_close(): void
    {
        $this->seedRow('2026-08-28', 6518.12109375);

        $report = $this->service([
            'entries' => [['date' => '2026-08-28', 'close' => null]],
        ])->import('2026-08-28', '2026-08-31');

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
        $this->assertSame(['2026-08-28'], $report->preserved);
    }

    public function test_a_legacy_dates_only_payload_establishes_sessions_without_erasing_closes(): void
    {
        $this->seedRow('2024-02-01', 7000.50);

        $report = $this->service([
            'dates' => ['2024-02-01', '2024-02-02'],
        ])->import('2024-02-01', '2024-02-28');

        // The session it already knew keeps its value...
        $this->assertEqualsWithDelta(7000.50, (float) $this->storedClose('2024-02-01'), 0.000001);
        // ...and the one it did not is established with an unknown close.
        $this->assertDatabaseHas('trading_days', ['date' => '2024-02-02', 'close' => null]);
        $this->assertSame(2, $report->providerSessions());
        $this->assertSame(0, $report->providerClosesCount());
    }

    public function test_a_combined_payload_prefers_entries_over_the_legacy_dates_list(): void
    {
        // Exactly the shape the real script emits: both keys, always.
        $report = $this->service([
            'dates' => ['2026-08-27', '2026-08-28', '2026-08-31'],
            'entries' => [
                ['date' => '2026-08-27', 'close' => 6521.75],
                ['date' => '2026-08-28', 'close' => 6518.12109375],
                ['date' => '2026-08-31', 'close' => 6525.47802734375],
            ],
        ])->import('2026-08-27', '2026-08-31');

        // Taking the `dates` branch merely because the key exists would throw
        // away every close in the response.
        $this->assertSame(3, $report->providerClosesCount());
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
    }

    public function test_the_provider_is_asked_for_more_than_is_persisted(): void
    {
        config(['trading_days.fetch_buffer_days' => 7]);

        $captured = [];

        $report = $this->service([
            'entries' => [
                // Outside the requested range: fetched for boundary safety,
                // and must not be written.
                ['date' => '2026-08-24', 'close' => 6400.0],
                ['date' => '2026-08-28', 'close' => 6518.12109375],
            ],
        ], function (array $args) use (&$captured) {
            $captured = $args;
        })->import('2026-08-28', '2026-08-31');

        $this->assertContains('--start=2026-08-21', $captured, 'The fetch window should reach back past the requested start.');
        $this->assertContains('--end=2026-08-31', $captured);

        $this->assertSame('2026-08-21', $report->fetchedFrom);
        $this->assertSame(['2026-08-28'], array_keys($report->providerCloses));
        $this->assertDatabaseMissing('trading_days', ['date' => '2026-08-24']);
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
    }

    public function test_the_august_28_production_regression(): void
    {
        // The exact production state: a session recorded, its close unknown,
        // with good values either side of it.
        TradingDay::create(['date' => '2026-08-27', 'close' => 6521.75]);
        TradingDay::create(['date' => '2026-08-28', 'close' => null]);
        TradingDay::create(['date' => '2026-08-31', 'close' => 6525.47802734375]);

        $this->service([
            'dates' => ['2026-08-27', '2026-08-28', '2026-08-31'],
            'entries' => [
                ['date' => '2026-08-27', 'close' => 6521.75],
                ['date' => '2026-08-28', 'close' => 6518.12109375],
                ['date' => '2026-08-31', 'close' => 6525.47802734375],
            ],
        ])->import('2026-08-27', '2026-08-31');

        $this->assertNotNull(
            TradingDay::whereDate('date', '2026-08-28')->value('close'),
            'The provider supplied a close for 2026-08-28 and it was not persisted.',
        );

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
        $this->assertEqualsWithDelta(6521.75, (float) $this->storedClose('2026-08-27'), 0.000001);
        $this->assertEqualsWithDelta(6525.47802734375, (float) $this->storedClose('2026-08-31'), 0.000001);

        // One row per session: the model and the writer must agree on the key.
        $this->assertSame(3, DB::table('trading_days')->count());
    }

    public function test_a_model_write_and_an_import_write_share_one_primary_key(): void
    {
        // The model used to store "2026-08-28 00:00:00" where the importer
        // stored "2026-08-28", so an upsert inserted a second row for the same
        // session instead of updating the first.
        TradingDay::create(['date' => '2026-08-28', 'close' => null]);

        $this->assertSame('2026-08-28', DB::table('trading_days')->value('date'));

        $this->service([
            'entries' => [['date' => '2026-08-28', 'close' => 6518.12109375]],
        ])->import('2026-08-28', '2026-08-28');

        $this->assertSame(1, DB::table('trading_days')->count());
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->storedClose('2026-08-28'), 0.000001);
    }
}

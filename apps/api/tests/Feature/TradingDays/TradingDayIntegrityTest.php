<?php

namespace Tests\Feature\TradingDays;

use App\Models\TradingDay;
use App\Services\PythonRunner;
use App\Services\TradingDayWriter;
use Database\Seeders\TradingDaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end: the command, the seeder, and the guarantee that a command cannot
 * report success while the database disagrees with the provider.
 */
class TradingDayIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private string $seedDir;

    private string $realDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Captured before the redirect below, so a test can still reach the
        // real migrations directory.
        $this->realDatabasePath = database_path();

        // trading-days:build rewrites the checked-in seeder file. Redirect the
        // database path so a test run cannot touch the real one.
        $this->seedDir = sys_get_temp_dir().'/breakout-trading-days-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->seedDir.'/seeders/data');
        $this->app->useDatabasePath($this->seedDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->seedDir);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<int, array{date: string, close: float|null}>  $entries
     */
    private function mockProvider(array $entries): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('run')->andReturn([
            'ok' => true,
            'stderr' => '',
            'stdout' => '',
            'json' => [
                'tickers' => [[
                    'ticker' => '^JKSE',
                    'dates' => array_column($entries, 'date'),
                    'entries' => $entries,
                ]],
            ],
        ]);

        $this->app->instance(PythonRunner::class, $runner);
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

    public function test_the_build_command_repairs_the_august_28_production_state(): void
    {
        $this->seedDay('2026-08-27', 6521.75);
        $this->seedDay('2026-08-28', null);
        $this->seedDay('2026-08-31', 6525.47802734375);

        $this->mockProvider([
            ['date' => '2026-08-27', 'close' => 6521.75],
            ['date' => '2026-08-28', 'close' => 6518.12109375],
            ['date' => '2026-08-31', 'close' => 6525.47802734375],
        ]);

        $exit = Artisan::call('trading-days:build', ['--from' => '2026-08-27', '--to' => '2026-08-31']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Repaired null closes: 2026-08-28', $output);
        $this->assertStringContainsString('0 null closes', $output);

        // Asserted from a fresh read: the incident happened after a reported upsert.
        $this->assertNotNull(TradingDay::whereDate('date', '2026-08-28')->value('close'));
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        $this->assertEqualsWithDelta(6521.75, (float) $this->close('2026-08-27'), 0.000001);
        $this->assertEqualsWithDelta(6525.47802734375, (float) $this->close('2026-08-31'), 0.000001);
    }

    public function test_the_build_command_reports_sessions_the_provider_could_not_fill(): void
    {
        $this->seedDay('2026-08-28', null);

        // The provider knows the session happened but not what it closed at.
        $this->mockProvider([['date' => '2026-08-28', 'close' => null]]);

        $exit = Artisan::call('trading-days:build', ['--from' => '2026-08-28', '--to' => '2026-08-28']);
        $output = Artisan::output();

        // Not a failure -- the provider had nothing to give -- but not silent.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 null closes', $output);
        $this->assertStringContainsString('Database still contains NULL IHSG closes: 2026-08-28', $output);
    }

    public function test_the_build_command_fails_when_a_supplied_close_is_not_persisted(): void
    {
        $this->seedDay('2026-08-28', null);

        $this->mockProvider([['date' => '2026-08-28', 'close' => 6518.12109375]]);

        // A writer that accepts the record and silently does not store it --
        // which is exactly what the production run looked like from the
        // outside. The command must consult the database rather than believe
        // the write, so this has to fail.
        $writer = Mockery::mock(TradingDayWriter::class)->makePartial();
        $writer->shouldReceive('write')->andReturn([
            'dates' => ['2026-08-28'],
            'with_close' => ['2026-08-28'],
            'date_only' => [],
            'repaired' => [],
            'preserved' => [],
            'written' => 1,
        ]);
        $this->app->instance(TradingDayWriter::class, $writer);

        $exit = Artisan::call('trading-days:build', ['--from' => '2026-08-28', '--to' => '2026-08-28']);
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'A close the provider supplied but the database does not hold is a failed import.');
        $this->assertStringContainsString('still holds as NULL: 2026-08-28', $output);
        $this->assertStringContainsString('did not persist what the provider returned', $output);
    }

    public function test_the_build_command_never_lets_the_provider_erase_a_known_close(): void
    {
        $this->seedDay('2026-08-28', 6518.12109375);

        $this->mockProvider([['date' => '2026-08-28', 'close' => null]]);

        Artisan::call('trading-days:build', ['--from' => '2026-08-28', '--to' => '2026-08-28']);
        $output = Artisan::output();

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        $this->assertStringContainsString('Kept existing closes the provider could not confirm', $output);
    }

    public function test_the_seeder_cannot_downgrade_a_known_close_to_unknown(): void
    {
        $this->seedDay('2026-08-28', 6518.12109375);

        // A seed file generated before the repair, still saying "unknown".
        File::put(
            $this->seedDir.'/seeders/data/trading_days.php',
            "<?php\n\nreturn [\n    ['date' => '2026-08-28', 'close' => null],\n    ['date' => '2026-09-01', 'close' => 6600.5],\n];\n"
        );

        (new TradingDaySeeder)->run();

        // The repair survives db:seed...
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        // ...and the seeder can still add and fill sessions.
        $this->assertEqualsWithDelta(6600.5, (float) $this->close('2026-09-01'), 0.000001);
    }

    public function test_the_seeder_fills_an_unknown_close_it_has_a_value_for(): void
    {
        $this->seedDay('2026-08-28', null);

        File::put(
            $this->seedDir.'/seeders/data/trading_days.php',
            "<?php\n\nreturn [\n    ['date' => '2026-08-28', 'close' => 6518.12109375],\n];\n"
        );

        (new TradingDaySeeder)->run();

        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
    }

    public function test_the_migration_merges_rows_split_across_two_date_spellings(): void
    {
        // The corrupted shape: a model write stored the long spelling with an
        // unknown close, and a later importer write inserted a second row
        // under the short one. Ordered reads returned the NULL.
        DB::table('trading_days')->insert([
            ['date' => '2026-08-28 00:00:00', 'close' => null, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-08-28', 'close' => 6518.12109375, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-08-31', 'close' => 6525.47802734375, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require $this->realDatabasePath.'/migrations/2026_09_01_000000_normalize_trading_day_date_keys.php';

        $this->assertSame(3, DB::table('trading_days')->count());

        $migration->up();

        // One row per session, and the merge kept the value that was known.
        $this->assertSame(2, DB::table('trading_days')->count());
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
        $this->assertEqualsWithDelta(6525.47802734375, (float) $this->close('2026-08-31'), 0.000001);

        // Idempotent: a second pass finds nothing left to merge.
        $migration->up();
        $this->assertSame(2, DB::table('trading_days')->count());
        $this->assertEqualsWithDelta(6518.12109375, (float) $this->close('2026-08-28'), 0.000001);
    }

    public function test_the_seeder_file_rewrite_can_be_skipped(): void
    {
        $this->mockProvider([['date' => '2026-08-28', 'close' => 6518.12109375]]);

        Artisan::call('trading-days:build', [
            '--from' => '2026-08-28',
            '--to' => '2026-08-28',
            '--no-seeder-sync' => true,
        ]);

        $this->assertStringContainsString('Seeder file left untouched', Artisan::output());
        $this->assertFalse(File::exists($this->seedDir.'/seeders/data/trading_days.php'));
    }
}

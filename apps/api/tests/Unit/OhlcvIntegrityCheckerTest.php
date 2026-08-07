<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Models\TradingDay;
use App\Services\OhlcvIntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OhlcvIntegrityCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_gaps_duplicates_and_extras(): void
    {
        $now = now();

        // The integrity checker is supposed to *detect* duplicate rows for
        // (asset_id, date), so we have to bypass the schema's unique
        // constraint to seed them. Drop the unique index for the duration
        // of this test only.
        Schema::table('price_bars', function ($table) {
            $table->dropUnique(['asset_id', 'date']);
        });

        $assetId = DB::table('assets')->insertGetId([
            'symbol' => 'AAA',
            'name' => 'AAA Corp',
        ]);

        foreach (['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05'] as $date) {
            DB::table('trading_days')->insert([
                'date' => $date,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('price_bars')->insert([
            [
                'asset_id' => $assetId,
                'date' => '2024-01-01',
                'open' => 10,
                'high' => 11,
                'low' => 9,
                'close' => 10.5,
                'volume' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'asset_id' => $assetId,
                'date' => '2024-01-02',
                'open' => 11,
                'high' => 12,
                'low' => 10,
                'close' => 11.5,
                'volume' => 1200,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'asset_id' => $assetId,
                'date' => '2024-01-04',
                'open' => 12,
                'high' => 13,
                'low' => 11,
                'close' => 12.5,
                'volume' => 1300,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'asset_id' => $assetId,
                'date' => '2024-01-04',
                'open' => 12.1,
                'high' => 13.1,
                'low' => 11.1,
                'close' => 12.6,
                'volume' => 1400,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'asset_id' => $assetId,
                'date' => '2024-01-06',
                'open' => 13,
                'high' => 14,
                'low' => 12,
                'close' => 13.5,
                'volume' => 1500,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $asset = Asset::findOrFail($assetId);

        $checker = new OhlcvIntegrityChecker(new TradingDay, new Asset);
        $report = $checker->checkAsset($asset);

        $this->assertSame($assetId, $report['asset_id']);
        $this->assertSame('AAA', $report['symbol']);
        $this->assertSame('2024-01-01', $report['range_start']);
        $this->assertSame('2024-01-06', $report['range_end']);
        $this->assertSame('2024-01-01', $report['first_bar']);
        $this->assertSame('2024-01-06', $report['last_bar']);
        $this->assertSame('2024-01-01', $report['global_first_bar']);
        $this->assertSame('2024-01-06', $report['global_last_bar']);
        $this->assertSame(5, $report['total_rows']);
        $this->assertSame(4, $report['unique_rows']);
        $this->assertSame(5, $report['expected_trading_days']);
        $this->assertSame(4, $report['actual_trading_days']);
        $this->assertSame(['2024-01-03', '2024-01-05'], $report['missing_trading_days']);
        $this->assertSame(['2024-01-06'], $report['extra_bars']);
        $this->assertSame(['2024-01-04'], $report['duplicate_bars']);
        $this->assertFalse($report['is_consistent']);
        $this->assertTrue($report['has_trading_day_data']);
    }

    public function test_handles_asset_without_bars(): void
    {
        $assetId = DB::table('assets')->insertGetId([
            'symbol' => 'BBB',
            'name' => 'BBB Corp',
        ]);

        $asset = Asset::findOrFail($assetId);

        $checker = new OhlcvIntegrityChecker(new TradingDay, new Asset);
        $report = $checker->checkAsset($asset);

        $this->assertSame(0, $report['total_rows']);
        $this->assertSame(0, $report['unique_rows']);
        $this->assertNull($report['range_start']);
        $this->assertNull($report['range_end']);
        $this->assertNull($report['global_first_bar']);
        $this->assertNull($report['global_last_bar']);
        $this->assertSame([], $report['missing_trading_days']);
        $this->assertSame([], $report['extra_bars']);
        $this->assertSame([], $report['duplicate_bars']);
        $this->assertFalse($report['has_trading_day_data']);
    }
}

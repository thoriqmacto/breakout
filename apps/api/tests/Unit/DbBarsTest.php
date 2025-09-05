<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Services\DbBars;

class DbBarsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flush_inserts_rows(): void
    {
        $assetId = DB::table('assets')->insertGetId([
            'symbol' => 'AAA',
            'name' => 'AAA',
        ]);

        $service = new DbBars(2, false);
        $service->add([
            'asset_id' => $assetId,
            'date' => '2024-01-01',
            'open' => 1,
            'high' => 1,
            'low' => 1,
            'close' => 1,
            'volume' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->add([
            'asset_id' => $assetId,
            'date' => '2024-01-02',
            'open' => 2,
            'high' => 2,
            'low' => 2,
            'close' => 2,
            'volume' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->flush();

        $this->assertEquals(2, $service->inserted());
        $this->assertEquals(2, DB::table('price_bars')->count());
    }

    public function test_dry_run_skips_db_insert(): void
    {
        $assetId = DB::table('assets')->insertGetId([
            'symbol' => 'BBB',
            'name' => 'BBB',
        ]);

        $service = new DbBars(2, true);
        $service->add([
            'asset_id' => $assetId,
            'date' => '2024-01-01',
            'open' => 1,
            'high' => 1,
            'low' => 1,
            'close' => 1,
            'volume' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->flush();

        $this->assertEquals(1, $service->inserted());
        $this->assertEquals(0, DB::table('price_bars')->count());
    }
}


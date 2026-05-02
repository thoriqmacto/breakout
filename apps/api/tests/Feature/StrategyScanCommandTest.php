<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StrategyScanCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scans_and_returns_matching_symbol(): void
    {
        Asset::create(['symbol' => 'AAA', 'name' => 'Asset AAA']);
        Asset::create(['symbol' => 'BBB', 'name' => 'Asset BBB']);

        // Laravel's bulk insert requires every row to have the same shape;
        // normalize to a uniform column set with sensible defaults.
        $template = [
            'ret_1' => 0.0,
            'vol_ratio_20' => 1.0,
            'avg_net_norm' => 0.0,
            'pbas' => 0,
            'has_broker' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('features_daily')->insert([
            array_merge($template, [
                'symbol' => 'AAA',
                'date' => '2026-02-01',
                'avg_net_norm' => 0.04,
            ]),
            array_merge($template, [
                'symbol' => 'AAA',
                'date' => '2026-02-02',
                'ret_1' => -0.011,
                'vol_ratio_20' => 0.72,
                'avg_net_norm' => 0.03,
                'pbas' => 82,
            ]),
            array_merge($template, [
                'symbol' => 'BBB',
                'date' => '2026-02-01',
                'avg_net_norm' => -0.03,
            ]),
            array_merge($template, [
                'symbol' => 'BBB',
                'date' => '2026-02-02',
                'ret_1' => -0.015,
                'vol_ratio_20' => 0.70,
                'avg_net_norm' => -0.01,
                'pbas' => 20,
            ]),
        ]);

        $exit = Artisan::call('strategy:scan-accumulation', [
            '--date' => '2026-02-02',
            '--anchor-date' => '2026-02-01',
        ]);

        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('Scan date: 2026-02-02 | Anchor window: 2026-02-01 → 2026-02-02', $output);
        $this->assertStringContainsString('AAA', $output);
        $this->assertStringNotContainsString('BBB', $output);
    }

    public function test_fails_when_anchor_date_is_after_scan_date(): void
    {
        DB::table('features_daily')->insert([
            'symbol' => 'AAA',
            'date' => '2026-02-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('strategy:scan-accumulation', [
            '--date' => '2026-02-02',
            '--anchor-date' => '2026-02-03',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --anchor-date value', Artisan::output());
    }
}

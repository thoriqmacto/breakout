<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradingDaysControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_trading_days_endpoint_returns_configured_symbols_and_statistics(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $configuredSymbols = ['ACES', 'ADRO', 'AKRA', 'AMMN', 'ANTM', 'ASII'];
        foreach ($configuredSymbols as $symbol) {
            Asset::query()->create([
                'symbol' => $symbol,
                'name' => $symbol,
            ]);
        }

        $indexCloses = [
            '2024-01-02' => 7000.0,
            '2024-01-03' => 7350.0,
            '2024-01-04' => 6615.0,
        ];

        foreach ($indexCloses as $date => $close) {
            TradingDay::query()->create([
                'date' => $date,
                'close' => $close,
            ]);
        }

        foreach ($configuredSymbols as $offset => $symbol) {
            $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();

            $base = 100 + ($offset * 5);
            foreach (array_keys($indexCloses) as $dateIndex => $date) {
                $close = match ($dateIndex) {
                    0 => $base,
                    1 => $base + 5,
                    default => $base - 10,
                };

                Price::query()->create([
                    'asset_id' => $asset->id,
                    'date' => $date,
                    'close' => $close,
                ]);
            }
        }

        $response = $this->getJson('/api/v1/trading-days');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(count($configuredSymbols), 'data.symbols');
        $response->assertJsonPath('data.symbols', $configuredSymbols);
        $response->assertJsonPath('data.pagination.total', count($indexCloses));

        $firstRowSymbols = array_keys($response->json('data.trading_days.0.symbols'));
        sort($firstRowSymbols);
        $expectedSymbols = $configuredSymbols;
        sort($expectedSymbols);
        $this->assertSame($expectedSymbols, $firstRowSymbols);

        $response->assertJsonPath('data.statistics.total_valid_trading_days', count($indexCloses));
        $response->assertJsonPath('data.statistics.highest_change.date', '2024-01-03');
        $response->assertJsonPath('data.statistics.lowest_change.date', '2024-01-04');

        $highestChange = (($indexCloses['2024-01-03'] - $indexCloses['2024-01-02']) / $indexCloses['2024-01-02']) * 100;
        $lowestChange = (($indexCloses['2024-01-04'] - $indexCloses['2024-01-03']) / $indexCloses['2024-01-03']) * 100;

        $payload = $response->json('data.statistics');
        $this->assertEqualsWithDelta($highestChange, $payload['highest_change']['change_percent'], 0.0001);
        $this->assertEqualsWithDelta($lowestChange, $payload['lowest_change']['change_percent'], 0.0001);
    }
}

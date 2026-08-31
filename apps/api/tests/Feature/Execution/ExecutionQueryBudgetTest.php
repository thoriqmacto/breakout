<?php

namespace Tests\Feature\Execution;

use App\Models\Asset;
use App\Models\BrokerSummaryWindow;
use App\Models\Price;
use App\Models\WatchlistScore;
use App\Services\Execution\ExecutionCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The workspace composes one page from five sources, so the shape that would
 * hurt is a query per row rather than a query per source.
 *
 * This asserts a budget, not an exact number: the point is that the count is
 * flat in the number of candidates, and a regression that reintroduces a
 * per-row lookup shows up as a multiple rather than as a slightly slower page.
 */
class ExecutionQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedUniverse(int $assetCount, int $offset = 0): string
    {
        $signalDate = '2026-02-27';

        for ($i = $offset; $i < $offset + $assetCount; $i++) {
            $symbol = sprintf('SYM%02d', $i);
            $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol, 'sector' => 'Energy']);

            $cursor = Carbon::parse('2026-01-05');
            $close = 1000.0 + $i;
            $written = 0;

            while ($written < 40) {
                if ($cursor->dayOfWeekIso >= 6) {
                    $cursor->addDay();

                    continue;
                }

                Price::create([
                    'asset_id' => $asset->id,
                    'date' => $cursor->toDateString(),
                    'open' => $close - 2,
                    'high' => $close + 3,
                    'low' => $close - 5,
                    'close' => $close,
                    'volume' => 10_000_000,
                ]);

                DB::table('trading_days')->insertOrIgnore(['date' => $cursor->toDateString()]);

                $close += 5;
                $written++;
                $cursor->addDay();
            }

            DB::table('features_daily')->insert([
                'symbol' => $symbol,
                'date' => $signalDate,
                'breakout20' => true,
                'close_pos' => 0.9,
                'vol_ratio_20' => 2.0,
                'turnover_value' => 50_000_000_000,
                'active_broker_count' => 20,
                'pbas' => 80,
                'valid_long_setup' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            WatchlistScore::create([
                'scan_date' => $signalDate,
                'symbol' => $symbol,
                'asset_id' => $asset->id,
                'version' => 'v1',
                'close' => $close,
                'score_total' => 80,
                'score_bas' => 80,
                'score_bcs' => 80,
                'lf_pass' => true,
                'rrf_pass' => true,
                'top_brokers' => [],
                'reasons' => [],
            ]);

            BrokerSummaryWindow::create([
                'asset_id' => $asset->id,
                'from_date' => $signalDate,
                'to_date' => $signalDate,
                'transaction_type' => (string) config('stockbit.defaults.transaction_type'),
            ]);
        }

        return $signalDate;
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_candidates(): void
    {
        $this->seedUniverse(4);
        $service = app(ExecutionCandidateService::class);

        $small = $this->countQueries(fn () => $service->candidates());

        // The snapshot service loads bars per asset by design -- one windowed
        // query each, which is the portable way to take "the most recent N per
        // asset". Everything else has to stay flat, so doubling the universe
        // may add at most one query per new asset.
        $this->seedUniverse(4, offset: 4);

        $large = $this->countQueries(fn () => $service->candidates());

        $this->assertLessThanOrEqual(
            $small + 4,
            $large,
            'Adding assets added more than one query each: something is resolving per row.',
        );
    }
}

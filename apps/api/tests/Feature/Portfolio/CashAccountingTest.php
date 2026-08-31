<?php

namespace Tests\Feature\Portfolio;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Price;
use App\Models\User;
use App\Services\Portfolio\PortfolioCalculator;
use App\Services\Portfolio\PositionPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Available cash must respond to trading.
 *
 * Before this, cash was `base + cash_movements` and nothing else: a sale
 * reduced the holding and booked a realized gain while the money never
 * arrived, and a purchase cost nothing. Total equity was wrong by the full
 * traded amount in both directions, which is the kind of error that looks
 * like a rounding problem on a small portfolio and like a fiction on a real
 * one.
 */
class CashAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function portfolio(float $baseCash): Portfolio
    {
        return Portfolio::create([
            'name' => 'Test',
            'base_ccy' => 'IDR',
            'cash_balance' => $baseCash,
            'cash_accounting_version' => 2,
        ]);
    }

    private function asset(string $symbol, ?float $latestClose = null): Asset
    {
        $asset = Asset::create(['symbol' => $symbol, 'name' => $symbol]);

        if ($latestClose !== null) {
            Price::create([
                'asset_id' => $asset->id,
                'date' => '2026-04-01',
                'open' => $latestClose,
                'high' => $latestClose,
                'low' => $latestClose,
                'close' => $latestClose,
                'volume' => 1_000,
            ]);
        }

        return $asset;
    }

    private function trade(Portfolio $portfolio, Asset $asset, string $side, float $qty, float $price, float $fee, string $at): Position
    {
        return Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'side' => $side,
            'qty_shares' => $qty,
            'price' => $price,
            'fee_rate' => 0,
            'fee_value' => $fee,
            'avg_price' => $side === 'entry'
                ? ($qty * $price + $fee) / $qty
                : ($qty * $price - $fee) / $qty,
            'executed_at' => $at,
        ]);
    }

    private function summary(Portfolio $portfolio): array
    {
        return app(PortfolioCalculator::class)->compute($portfolio->fresh());
    }

    public function test_a_full_round_trip_settles_exactly(): void
    {
        $portfolio = $this->portfolio(1_000_000);
        $asset = $this->asset('AAA');

        // Buy 100 @ 1,000 with a 150 fee: 100,000 + 150 = 100,150 out.
        $this->trade($portfolio, $asset, 'entry', 100, 1_000, 150, '2026-03-01');

        $afterBuy = $this->summary($portfolio);
        $this->assertSame(899_850.0, $afterBuy['cash_balance']);

        // Sell 100 @ 1,100 with a 275 fee: 110,000 - 275 = 109,725 in.
        $this->trade($portfolio, $asset, 'exit', 100, 1_100, 275, '2026-03-15');

        $afterSell = $this->summary($portfolio);

        $this->assertSame(1_009_575.0, $afterSell['cash_balance']);

        // The entry fee is already inside the cost basis and the exit fee is
        // taken on disposal, so each is felt exactly once.
        $this->assertSame(9_575.0, $afterSell['realized_pl']);

        $this->assertSame(0.0, $afterSell['total_market_value']);

        // Realized P/L is not added to cash a second time: the proceeds
        // already contain it.
        $this->assertSame(1_009_575.0, $afterSell['total_equity']);
    }

    public function test_a_partial_exit_settles_only_what_was_sold(): void
    {
        $portfolio = $this->portfolio(1_000_000);
        $asset = $this->asset('AAA', latestClose: 1_200);

        $this->trade($portfolio, $asset, 'entry', 100, 1_000, 150, '2026-03-01');
        $this->trade($portfolio, $asset, 'exit', 40, 1_100, 110, '2026-03-15');

        $summary = $this->summary($portfolio);
        $holding = $summary['holdings'][0];

        $this->assertSame(60.0, $holding['qty']);

        // Average cost is unchanged by an exit: (100,000 + 150) / 100.
        $this->assertSame(1_001.5, $holding['avg_cost']);

        // Realized on 40: 40 * (1,100 - 1,001.5) - 110 = 3,830.
        $this->assertSame(3_830.0, $summary['realized_pl']);

        // Cash: 1,000,000 - 100,150 + (44,000 - 110) = 943,740.
        $this->assertSame(943_740.0, $summary['cash_balance']);

        // 60 shares at the latest close of 1,200.
        $this->assertSame(72_000.0, $summary['total_market_value']);
        $this->assertSame(1_015_740.0, $summary['total_equity']);
    }

    public function test_cash_movements_keep_their_signs(): void
    {
        $portfolio = $this->portfolio(1_000_000);

        foreach ([
            [CashMovement::KIND_DEPOSIT, 200_000],
            [CashMovement::KIND_DIVIDEND, 50_000],
            [CashMovement::KIND_WITHDRAW, 30_000],
            [CashMovement::KIND_FEE, 10_000],
        ] as [$kind, $amount]) {
            CashMovement::create([
                'portfolio_id' => $portfolio->id,
                'kind' => $kind,
                'amount' => $amount,
                'executed_at' => '2026-03-10',
            ]);
        }

        // An adjustment takes its value as given, so it can correct either way.
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_ADJUSTMENT,
            'amount' => -15_000,
            'executed_at' => '2026-03-11',
        ]);

        $summary = $this->summary($portfolio);

        $this->assertSame(195_000.0, $summary['non_trade_cash_flow']);
        $this->assertSame(0.0, $summary['trade_cash_flow']);
        $this->assertSame(1_195_000.0, $summary['cash_balance']);
    }

    public function test_editing_and_deleting_a_position_corrects_cash_by_itself(): void
    {
        $portfolio = $this->portfolio(1_000_000);
        $asset = $this->asset('AAA');

        $position = $this->trade($portfolio, $asset, 'entry', 100, 1_000, 150, '2026-03-01');
        $this->assertSame(899_850.0, $this->summary($portfolio)['cash_balance']);

        // Corrected price. Nothing synchronises: the settlement is derived
        // from the row, so the row is the only thing that had to change.
        $position->update(['price' => 900, 'fee_value' => 135]);
        $this->assertSame(909_865.0, $this->summary($portfolio)['cash_balance']);

        $position->delete();
        $this->assertSame(1_000_000.0, $this->summary($portfolio)['cash_balance']);
    }

    public function test_the_summary_endpoint_explains_where_the_cash_came_from(): void
    {
        $user = User::factory()->create();
        $portfolio = $this->portfolio(1_000_000);
        $portfolio->update(['user_id' => $user->id]);
        $asset = $this->asset('AAA', latestClose: 1_200);

        $this->trade($portfolio, $asset, 'entry', 100, 1_000, 150, '2026-03-01');
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'amount' => 5_000,
            'executed_at' => '2026-03-10',
        ]);

        Sanctum::actingAs($user);

        $summary = $this->getJson('/api/v1/portfolios/'.$portfolio->id.'/summary')
            ->assertOk()
            ->json('data');

        // JSON hands whole amounts back as ints, so compare numerically.
        $this->assertEqualsWithDelta(1_000_000.0, $summary['base_cash_balance'], 0.001);
        $this->assertEqualsWithDelta(5_000.0, $summary['non_trade_cash_flow'], 0.001);
        $this->assertEqualsWithDelta(-100_150.0, $summary['trade_cash_flow'], 0.001);
        $this->assertEqualsWithDelta(904_850.0, $summary['cash_balance'], 0.001);

        // base + trade + non-trade = available, and the breakdown says so.
        $this->assertEqualsWithDelta(
            (float) $summary['base_cash_balance'] + (float) $summary['non_trade_cash_flow'] + (float) $summary['trade_cash_flow'],
            (float) $summary['cash_balance'],
            0.001,
        );
        $this->assertArrayHasKey('cash_breakdown', $summary);
        $this->assertEqualsWithDelta(-100_150.0, $summary['cash_breakdown']['trade_settlement'], 0.001);
    }

    public function test_a_manual_exit_cannot_sell_more_than_is_held(): void
    {
        $user = User::factory()->create();
        $portfolio = $this->portfolio(1_000_000);
        $portfolio->update(['user_id' => $user->id]);
        $asset = $this->asset('AAA');

        $this->trade($portfolio, $asset, 'entry', 100, 1_000, 0, '2026-03-01');

        Sanctum::actingAs($user);

        // 120 of a 100-share holding. The calculator would silently match only
        // 100 and drop the rest, leaving a ledger that no longer describes
        // anything that happened.
        $this->postJson('/api/v1/portfolios/'.$portfolio->id.'/positions', [
            'asset_id' => $asset->id,
            'side' => 'exit',
            'qty_shares' => 120,
            'price' => 1_100,
            'executed_at' => '2026-03-15',
        ])->assertStatus(422)->assertJsonPath('errors.qty_shares.0', fn (string $message): bool => str_contains($message, 'only 100 are held'));

        // Exactly the holding is fine.
        $this->postJson('/api/v1/portfolios/'.$portfolio->id.'/positions', [
            'asset_id' => $asset->id,
            'side' => 'exit',
            'qty_shares' => 100,
            'price' => 1_100,
            'executed_at' => '2026-03-15',
        ])->assertStatus(201);
    }

    public function test_the_migration_leaves_displayed_cash_exactly_where_it_was(): void
    {
        $portfolio = $this->portfolio(5_000_000);
        $asset = $this->asset('AAA');

        // A history entered under the old model, where the stored base already
        // reflected every one of these trades.
        $this->trade($portfolio, $asset, 'entry', 1_000, 2_000, 3_000, '2026-01-05');
        $this->trade($portfolio, $asset, 'exit', 400, 2_500, 1_500, '2026-02-10');
        $this->trade($portfolio, $asset, 'entry', 200, 2_200, 660, '2026-03-01');

        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_DEPOSIT,
            'amount' => 750_000,
            'executed_at' => '2026-02-01',
        ]);

        // Put the row back into the pre-migration state.
        DB::table('portfolios')->where('id', $portfolio->id)->update(['cash_accounting_version' => 1]);

        // What the old formula would have shown: base + movements only.
        $displayedBefore = 5_000_000.0 + 750_000.0;

        $migration = require database_path('migrations/2026_08_31_000100_rebase_portfolio_cash_for_trade_settlement.php');

        // The column already exists from the suite's migration run, so only
        // the data half is exercised here -- which is the half that can
        // corrupt a balance.
        $this->rebase($migration, $portfolio);

        $this->assertSame($displayedBefore, $this->summary($portfolio)['cash_balance']);

        // Idempotent: a second pass is a no-op, not a second subtraction.
        $this->rebase($migration, $portfolio);
        $this->assertSame($displayedBefore, $this->summary($portfolio)['cash_balance']);

        // And from here the term is live -- the next sale adds real money.
        $this->trade($portfolio, $asset, 'exit', 100, 3_000, 900, '2026-03-20');
        $this->assertSame($displayedBefore + 299_100.0, $this->summary($portfolio)['cash_balance']);
    }

    private function rebase(object $migration, Portfolio $portfolio): void
    {
        $reflection = new \ReflectionMethod($migration, 'tradeCashFlow');
        $flow = $reflection->invoke($migration, (int) $portfolio->id, new PositionPricing);

        $row = DB::table('portfolios')->where('id', $portfolio->id)->first();

        if ((int) $row->cash_accounting_version >= 2) {
            return;
        }

        DB::table('portfolios')->where('id', $portfolio->id)->update([
            'cash_balance' => round((float) $row->cash_balance - $flow, 2),
            'cash_accounting_version' => 2,
        ]);
    }
}

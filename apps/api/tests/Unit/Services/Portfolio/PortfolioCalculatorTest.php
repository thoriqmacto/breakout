<?php

namespace Tests\Unit\Services\Portfolio;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\Price;
use App\Services\Portfolio\PortfolioCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_average_cost_after_two_entries(): void
    {
        $portfolio = $this->makePortfolio(cashBalance: 10_000_000);
        $asset = Asset::create(['symbol' => 'BBCA', 'name' => 'BCA', 'sector' => 'Banks']);
        $this->setLatestPrice($asset, 11_000, '2026-04-01');

        $this->makePosition($portfolio, $asset, 'entry', 100, 10_000, '2026-03-01');
        $this->makePosition($portfolio, $asset, 'entry', 100, 12_000, '2026-03-15');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $this->assertCount(1, $summary['holdings']);
        $holding = $summary['holdings'][0];
        $this->assertSame(200.0, $holding['qty']);
        $this->assertSame(11_000.0, $holding['avg_cost']);
        $this->assertSame(2_200_000.0, $holding['market_value']);
        $this->assertSame(0.0, $holding['unrealized_pl']);
        $this->assertSame(0.0, $summary['realized_pl']);
        $this->assertSame(0.0, $summary['unrealized_pl']);

        // Buying costs money: 100*10,000 + 100*12,000 = 2,200,000 left the
        // cash account. Available cash used to ignore that entirely, so the
        // portfolio appeared to hold its full opening cash *and* the shares it
        // had bought with it.
        $this->assertSame(10_000_000.0, $summary['base_cash_balance']);
        $this->assertSame(-2_200_000.0, $summary['trade_cash_flow']);
        $this->assertSame(7_800_000.0, $summary['cash_balance']);

        // Cash converted to shares at cost, so equity is unchanged.
        $this->assertSame(10_000_000.0, $summary['total_equity']);
    }

    public function test_partial_exit_realizes_pl_and_keeps_avg_cost_unchanged(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'INCO', 'name' => 'INCO', 'sector' => 'Mining']);
        $this->setLatestPrice($asset, 6_500, '2026-04-01');

        $this->makePosition($portfolio, $asset, 'entry', 100, 5_000, '2026-03-01');
        $this->makePosition($portfolio, $asset, 'exit', 40, 6_000, '2026-03-15');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $holding = $summary['holdings'][0];
        $this->assertSame(60.0, $holding['qty']);
        $this->assertSame(5_000.0, $holding['avg_cost']);
        $this->assertSame(60.0 * 6_500.0, $holding['market_value']);

        // realized = (6000 - 5000) * 40 = 40_000
        $this->assertSame(40_000.0, $summary['realized_pl']);

        // unrealized = (6500 - 5000) * 60 = 90_000
        $this->assertSame(90_000.0, $summary['unrealized_pl']);
    }

    public function test_full_exit_drops_holding_and_keeps_realized_pl(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'ANTM', 'name' => 'ANTM', 'sector' => 'Mining']);
        $this->setLatestPrice($asset, 1_500, '2026-04-01');

        $this->makePosition($portfolio, $asset, 'entry', 200, 1_000, '2026-03-01');
        $this->makePosition($portfolio, $asset, 'exit', 200, 1_400, '2026-03-15');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $this->assertSame([], $summary['holdings']);
        $this->assertSame(80_000.0, $summary['realized_pl']);
        $this->assertSame(0.0, $summary['unrealized_pl']);
        $this->assertSame(0.0, $summary['total_market_value']);
    }

    public function test_fees_increase_avg_cost_on_entry_and_reduce_realized_pl_on_exit(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'BMRI', 'name' => 'BMRI', 'sector' => 'Banks']);
        $this->setLatestPrice($asset, 5_500, '2026-04-01');

        // entry: qty=100 @ 5000, fee_value=1000 → avg = (100*5000 + 1000) / 100 = 5_010
        $this->makePosition($portfolio, $asset, 'entry', 100, 5_000, '2026-03-01', feeValue: 1_000);
        // exit:  qty=50 @ 5500, fee_value=500 → realized = (5500-5010)*50 - 500 = 24_500 - 500 = 24_000
        $this->makePosition($portfolio, $asset, 'exit', 50, 5_500, '2026-03-15', feeValue: 500);

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $holding = $summary['holdings'][0];
        $this->assertSame(50.0, $holding['qty']);
        $this->assertSame(5_010.0, $holding['avg_cost']);
        $this->assertSame(24_000.0, $summary['realized_pl']);
    }

    public function test_cash_movements_change_balance_and_total_equity(): void
    {
        $portfolio = $this->makePortfolio(cashBalance: 1_000_000);
        $asset = Asset::create(['symbol' => 'TLKM', 'name' => 'TLKM']);
        $this->setLatestPrice($asset, 4_000, '2026-04-01');
        $this->makePosition($portfolio, $asset, 'entry', 100, 3_500, '2026-03-01');

        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_DEPOSIT,
            'amount' => 500_000,
            'executed_at' => '2026-03-10',
        ]);
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'amount' => 25_000,
            'executed_at' => '2026-03-20',
        ]);
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_WITHDRAW,
            'amount' => 100_000,
            'executed_at' => '2026-03-25',
        ]);
        CashMovement::create([
            'portfolio_id' => $portfolio->id,
            'kind' => CashMovement::KIND_FEE,
            'amount' => 5_000,
            'executed_at' => '2026-03-26',
        ]);

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        // non-trade = +500_000 + 25_000 - 100_000 - 5_000 = 420_000
        $this->assertSame(420_000.0, $summary['non_trade_cash_flow']);
        // trade = -(100 * 3_500) = -350_000
        $this->assertSame(-350_000.0, $summary['trade_cash_flow']);
        // cash = 1_000_000 + 420_000 - 350_000 = 1_070_000
        $this->assertSame(1_070_000.0, $summary['cash_balance']);
        // market = 100 * 4000 = 400_000
        $this->assertSame(400_000.0, $summary['total_market_value']);
        $this->assertSame(1_470_000.0, $summary['total_equity']);
    }

    public function test_allocation_by_symbol_and_sector(): void
    {
        $portfolio = $this->makePortfolio();
        $bbri = Asset::create(['symbol' => 'BBRI', 'name' => 'BBRI', 'sector' => 'Banks']);
        $bmri = Asset::create(['symbol' => 'BMRI', 'name' => 'BMRI', 'sector' => 'Banks']);
        $tlkm = Asset::create(['symbol' => 'TLKM', 'name' => 'TLKM', 'sector' => 'Telco']);
        $this->setLatestPrice($bbri, 5_000, '2026-04-01');
        $this->setLatestPrice($bmri, 5_000, '2026-04-01');
        $this->setLatestPrice($tlkm, 4_000, '2026-04-01');

        $this->makePosition($portfolio, $bbri, 'entry', 100, 5_000, '2026-03-01'); // mv 500_000
        $this->makePosition($portfolio, $bmri, 'entry', 100, 5_000, '2026-03-01'); // mv 500_000
        $this->makePosition($portfolio, $tlkm, 'entry', 100, 4_000, '2026-03-01'); // mv 400_000

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $this->assertSame(1_400_000.0, $summary['total_market_value']);

        $bySymbol = $summary['allocation_by_symbol'];
        $this->assertCount(3, $bySymbol);
        // largest first
        $this->assertContains($bySymbol[0]['symbol'], ['BBRI', 'BMRI']);
        $this->assertSame(500_000.0, $bySymbol[0]['value']);

        $bySector = collect($summary['allocation_by_sector'])->keyBy('sector');
        $this->assertSame(1_000_000.0, $bySector['Banks']['value']);
        $this->assertSame(400_000.0, $bySector['Telco']['value']);
        $this->assertEqualsWithDelta(71.4286, $bySector['Banks']['pct'], 0.001);
    }

    public function test_holding_without_latest_price_falls_back_to_cost_basis(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'NEWX', 'name' => 'NEWX']);
        $this->makePosition($portfolio, $asset, 'entry', 100, 1_500, '2026-03-01');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $holding = $summary['holdings'][0];
        $this->assertNull($holding['latest_close']);
        $this->assertSame(150_000.0, $holding['market_value']);
        $this->assertSame(0.0, $holding['unrealized_pl']);
        $this->assertNull($holding['unrealized_pl_pct']);
    }

    public function test_oversell_clamps_exit_quantity_to_holding(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'XYZ', 'name' => 'XYZ']);
        $this->setLatestPrice($asset, 1_000, '2026-04-01');

        $this->makePosition($portfolio, $asset, 'entry', 50, 1_000, '2026-03-01');
        // try to exit 100 — should clamp to 50
        $this->makePosition($portfolio, $asset, 'exit', 100, 1_200, '2026-03-15');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $this->assertSame([], $summary['holdings']);
        // realized = (1200 - 1000) * 50 = 10_000 (oversell clamped to 50)
        $this->assertSame(10_000.0, $summary['realized_pl']);
    }

    public function test_compute_is_deterministic_regardless_of_storage_order(): void
    {
        $portfolio = $this->makePortfolio();
        $asset = Asset::create(['symbol' => 'ASII', 'name' => 'ASII']);
        $this->setLatestPrice($asset, 6_000, '2026-04-01');

        // create exit first, entry second — calculator must sort by executed_at
        $this->makePosition($portfolio, $asset, 'exit', 50, 5_500, '2026-03-15');
        $this->makePosition($portfolio, $asset, 'entry', 100, 5_000, '2026-03-01');

        $summary = app(PortfolioCalculator::class)->compute($portfolio->fresh());

        $holding = $summary['holdings'][0];
        $this->assertSame(50.0, $holding['qty']);
        $this->assertSame(5_000.0, $holding['avg_cost']);
        // realized = (5500 - 5000) * 50 = 25_000
        $this->assertSame(25_000.0, $summary['realized_pl']);
    }

    private function makePortfolio(float $cashBalance = 0.0): Portfolio
    {
        return Portfolio::create([
            'name' => 'Test PF',
            'base_ccy' => 'IDR',
            'cash_balance' => $cashBalance,
        ]);
    }

    private function setLatestPrice(Asset $asset, float $close, string $date): void
    {
        Price::create([
            'asset_id' => $asset->id,
            'date' => $date,
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1000,
        ]);
    }

    private function makePosition(
        Portfolio $portfolio,
        Asset $asset,
        string $side,
        float $qty,
        float $price,
        string $executedAt,
        float $feeValue = 0.0
    ): Position {
        return Position::create([
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'side' => $side,
            'qty_shares' => $qty,
            'price' => $price,
            'fee_rate' => 0,
            'fee_value' => $feeValue,
            'avg_price' => $price,
            'executed_at' => $executedAt,
        ]);
    }
}

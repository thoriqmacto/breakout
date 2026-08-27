<?php

namespace Tests\Unit\Services\Portfolio;

use App\Services\Portfolio\PositionPricing;
use Tests\TestCase;

/**
 * The single arithmetic path shared by the manual form and the importer.
 *
 * The reason it is shared: a broker knows the exact money it charged, a form
 * knows a percentage, and if those two produced different cost bases the
 * ledger would disagree with the contract note.
 */
class PositionPricingTest extends TestCase
{
    private PositionPricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = new PositionPricing;
    }

    public function test_a_rate_reproduces_the_previous_manual_behaviour_exactly(): void
    {
        // What the controller used to compute inline: fee = qty*price*rate/100
        // and avg = price * (1 + rate/100).
        $result = $this->pricing->normalize('entry', 1000, 2000, feeRate: 0.15);

        $this->assertEqualsWithDelta(0.15, $result['fee_rate'], 1e-9);
        $this->assertEqualsWithDelta(3000.0, $result['fee_value'], 1e-9);
        $this->assertEqualsWithDelta(2000 * 1.0015, $result['avg_price'], 1e-9);
    }

    public function test_a_rate_on_an_exit_reduces_the_effective_proceeds(): void
    {
        $result = $this->pricing->normalize('exit', 1000, 2000, feeRate: 0.25);

        $this->assertEqualsWithDelta(5000.0, $result['fee_value'], 1e-9);
        $this->assertEqualsWithDelta(2000 * 0.9975, $result['avg_price'], 1e-9);
    }

    public function test_an_exact_fee_wins_over_a_rate_and_derives_the_rate_from_it(): void
    {
        // Both supplied; the money is authoritative.
        $result = $this->pricing->normalize('entry', 5000, 2000, feeRate: 99.0, feeValue: 15_000.0);

        $this->assertEqualsWithDelta(15_000.0, $result['fee_value'], 1e-9);
        $this->assertEqualsWithDelta(0.15, $result['fee_rate'], 1e-9);
        // (10,000,000 + 15,000) / 5,000
        $this->assertEqualsWithDelta(2003.0, $result['avg_price'], 1e-9);
    }

    public function test_an_exact_fee_on_an_exit_nets_the_proceeds(): void
    {
        $result = $this->pricing->normalize('exit', 500, 3720, feeValue: 4650.0);

        // (1,860,000 - 4,650) / 500
        $this->assertEqualsWithDelta(3710.7, $result['avg_price'], 1e-9);
        $this->assertEqualsWithDelta(1_855_350.0, $this->pricing->netAmount('exit', 500, 3720, 4650.0), 1e-9);
    }

    public function test_net_amount_matches_the_brokers_own_figure(): void
    {
        // The two BRPT buys, checked against Stockbit's netamount.
        $this->assertEqualsWithDelta(
            10_015_000.0,
            $this->pricing->netAmount('entry', 5000, 2000, 15_000.0),
            1e-9,
        );
        $this->assertEqualsWithDelta(
            9_113_650.0,
            $this->pricing->netAmount('entry', 6500, 1400, 13_650.0),
            1e-9,
        );
    }

    public function test_a_zero_value_trade_reports_no_rate_rather_than_dividing_by_zero(): void
    {
        $result = $this->pricing->normalize('entry', 100, 0.0, feeValue: 500.0);

        $this->assertSame(0.0, $result['fee_rate']);
        $this->assertEqualsWithDelta(5.0, $result['avg_price'], 1e-9);
    }

    public function test_a_zero_quantity_falls_back_to_the_raw_price(): void
    {
        $this->assertEqualsWithDelta(
            1234.0,
            $this->pricing->effectiveUnitPrice('entry', 0, 1234, 100),
            1e-9,
        );
    }

    public function test_an_unknown_side_is_treated_as_an_entry(): void
    {
        $result = $this->pricing->normalize('SOMETHING', 100, 1000, feeValue: 1000.0);

        $this->assertEqualsWithDelta(1010.0, $result['avg_price'], 1e-9);
    }
}

<?php

namespace Tests\Unit;

use App\Support\BrokerSummaryTransformer;
use Tests\TestCase;

/**
 * The buy and sell branches of toFacts() must agree about signs.
 *
 * broker_summary_facts stores buy_* and sell_* as unsigned columns, with
 * direction carried by the signed net_* columns. The sell branch had always
 * taken abs(), because Stockbit returns sell figures negative; the buy branch
 * had not. A negative in a buy row therefore reached MariaDB unchanged and was
 * rejected with SQLSTATE[22003] 1264, "Out of range value for column
 * 'buy_lot'". Under SQLite, which is typeless, the same data had imported
 * without complaint for as long as the project ran on it.
 */
class BrokerSummaryMagnitudeTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $buy
     * @param  array<string, mixed>  $sell
     * @return array<string, mixed>
     */
    private function fact(array $buy, array $sell = []): array
    {
        $facts = BrokerSummaryTransformer::toFacts('BBCA', [
            'data' => [
                'broker_summary' => [
                    'brokers_buy' => [$buy + ['netbs_broker_code' => 'AB', 'netbs_date' => '2026-08-26']],
                    'brokers_sell' => $sell === []
                        ? []
                        : [$sell + ['netbs_broker_code' => 'AB', 'netbs_date' => '2026-08-26']],
                ],
            ],
        ], 'TRANSACTION_TYPE_NET');

        $this->assertCount(1, $facts, 'Expected exactly one aggregated fact.');

        return $facts[0];
    }

    public function test_a_negative_buy_lot_is_stored_as_a_magnitude(): void
    {
        $fact = $this->fact(['blot' => -5000, 'blotv' => -500000, 'bval' => -1250.5, 'bvalv' => -1250.5]);

        $this->assertSame(5000, $fact['buy_lot']);
        $this->assertSame(500000, $fact['buy_volume']);
        $this->assertSame(1250.5, $fact['buy_value']);
        $this->assertSame(1250.5, $fact['buy_value_v']);
    }

    public function test_a_positive_buy_lot_is_unchanged(): void
    {
        $fact = $this->fact(['blot' => 5000, 'blotv' => 500000, 'bval' => 1250.5]);

        $this->assertSame(5000, $fact['buy_lot']);
        $this->assertSame(500000, $fact['buy_volume']);
        $this->assertSame(1250.5, $fact['buy_value']);
    }

    public function test_the_sell_side_keeps_taking_magnitudes(): void
    {
        $fact = $this->fact(['blot' => 100], ['slot' => -400, 'slotv' => -40000, 'sval' => -900.25]);

        $this->assertSame(400, $fact['sell_lot']);
        $this->assertSame(40000, $fact['sell_volume']);
        $this->assertSame(900.25, $fact['sell_value']);
    }

    /**
     * Direction still has to survive somewhere, and net_lot is the signed
     * column that carries it. Taking magnitudes on both sides must not flatten
     * a distributing broker into an accumulating one.
     */
    public function test_net_stays_signed_and_equals_buy_minus_sell(): void
    {
        $fact = $this->fact(
            ['blot' => 100, 'blotv' => 10000, 'bval' => 250.0],
            ['slot' => -400, 'slotv' => -40000, 'sval' => -900.0],
        );

        $this->assertSame(-300, $fact['net_lot']);
        $this->assertSame(-30000, $fact['net_volume']);
        $this->assertSame(-650.0, $fact['net_value']);
    }

    /**
     * Every column the schema declares unsigned must come out non-negative,
     * whatever sign the feed used.
     */
    public function test_no_unsigned_column_is_ever_negative(): void
    {
        $fact = $this->fact(
            ['blot' => -1, 'blotv' => -2, 'bval' => -3.0, 'bvalv' => -4.0],
            ['slot' => -5, 'slotv' => -6, 'sval' => -7.0, 'svalv' => -8.0],
        );

        foreach ([
            'buy_lot', 'buy_volume', 'buy_value', 'buy_value_v',
            'sell_lot', 'sell_volume', 'sell_value', 'sell_value_v',
        ] as $column) {
            $this->assertGreaterThanOrEqual(
                0,
                $fact[$column],
                "{$column} is unsigned in the schema but the transformer produced a negative.",
            );
        }
    }
}

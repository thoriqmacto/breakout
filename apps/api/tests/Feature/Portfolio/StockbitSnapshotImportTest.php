<?php

namespace Tests\Feature\Portfolio;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The portfolio snapshot half of the importer.
 *
 * A snapshot is a statement to check the books against, not data to load. It
 * creates nothing on its own; the transaction history stays the source of
 * truth, and the only thing a snapshot can add is an explicitly requested
 * opening position for a holding no history explains.
 */
class StockbitSnapshotImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);

        $this->portfolio = Portfolio::create([
            'user_id' => $this->user->id,
            'name' => 'Main',
            'base_ccy' => 'IDR',
            'year' => 2026,
            'cash_balance' => 0,
        ]);
    }

    private function asset(string $symbol = 'BRPT'): Asset
    {
        return Asset::create(['symbol' => $symbol, 'name' => $symbol.' Tbk']);
    }

    /**
     * The BRPT snapshot from the real payload.
     */
    private function snapshot(float $cash = 5_989_968.15, float $shares = 11_500): string
    {
        return (string) json_encode([
            'message' => 'Success get portfolio',
            'data' => [
                'summary' => [
                    'trading' => ['balance' => $cash],
                    'amount' => ['invested' => 139_432_335.33],
                    'profit_loss' => ['net' => -3_269_335.33],
                    'equity' => 142_152_968.15,
                ],
                'results' => [[
                    'symbol' => 'BRPT',
                    'qty' => ['balance' => ['lot' => $shares / 100, 'share' => $shares]],
                    'price' => ['latest' => 1840, 'average' => ['price' => 1663.3609]],
                    'asset' => [
                        'unrealised' => [
                            'market_value' => 21_160_000,
                            'profit_loss' => 2_031_349.65,
                            'gain' => 0.106194,
                        ],
                        'amount_invested' => 19_128_650.35,
                    ],
                ]],
            ],
        ]);
    }

    /**
     * Seed the ledger with the two BUYs that produce 11,500 BRPT shares at an
     * exact cost basis of 19,128,650.
     */
    private function seedBrptHistory(Asset $asset): void
    {
        $this->postJson("/api/v1/portfolios/{$this->portfolio->id}/imports/stockbit", [
            'payload' => (string) json_encode([
                'data' => ['history' => [['date' => 'Jun 2026', 'history_list' => [
                    [
                        'command' => 'BUY', 'symbol' => $asset->symbol, 'price' => 2000, 'shares' => 5000,
                        'amount' => 10_000_000, 'fee' => 15_000, 'netamount' => 10_015_000,
                        'status' => 'MATCH', 'date' => '19 May 2026', 'time' => '09:15:00', 'id' => 'b1',
                    ],
                    [
                        'command' => 'BUY', 'symbol' => $asset->symbol, 'price' => 1400, 'shares' => 6500,
                        'amount' => 9_100_000, 'fee' => 13_650, 'netamount' => 9_113_650,
                        'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => 'b2',
                    ],
                ]]]],
            ]),
        ])->assertOk();
    }

    private function preview(string $payload, array $options = [])
    {
        return $this->postJson(
            "/api/v1/portfolios/{$this->portfolio->id}/imports/stockbit/preview",
            ['payload' => $payload] + $options,
        );
    }

    private function commit(string $payload, array $options = [])
    {
        return $this->postJson(
            "/api/v1/portfolios/{$this->portfolio->id}/imports/stockbit",
            ['payload' => $payload] + $options,
        );
    }

    public function test_a_snapshot_reconciles_against_the_existing_history(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        $row = $this->preview($this->snapshot())->assertOk()->json('data.snapshot.positions.0');

        $this->assertSame('BRPT', $row['symbol']);
        $this->assertEqualsWithDelta(11_500.0, (float) $row['broker_shares'], 0.0001);
        $this->assertEqualsWithDelta(11_500.0, (float) $row['breakout_shares'], 0.0001);
        $this->assertTrue($row['shares_match']);

        $this->assertEqualsWithDelta(1663.3609, (float) $row['broker_average_price'], 0.0001);
        $this->assertEqualsWithDelta(1663.36087, (float) $row['breakout_average_cost'], 0.0001);
        $this->assertTrue($row['average_match']);

        // The broker's 19,128,650.35 is its rounded 4dp average multiplied
        // back out; the ledger's exact figure is 19,128,650. Within tolerance.
        $this->assertEqualsWithDelta(19_128_650.35, (float) $row['broker_amount_invested'], 0.01);
        $this->assertEqualsWithDelta(19_128_650.0, (float) $row['breakout_cost_basis'], 0.01);
        $this->assertTrue($row['invested_match']);
    }

    public function test_a_snapshot_never_duplicates_a_holding_history_already_explains(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        $this->assertSame(2, Position::query()->count());

        // Even with the fallback switched on, a holding the ledger already
        // reproduces is not eligible for a synthetic opening position.
        $response = $this->commit($this->snapshot(), ['create_snapshot_positions' => true])->assertOk();

        $this->assertFalse($response->json('data.snapshot.positions.0.opening_position_eligible'));
        $this->assertSame(0, $response->json('data.created.positions'));
        $this->assertSame(2, Position::query()->count());
    }

    public function test_the_opening_position_fallback_requires_an_explicit_opt_in(): void
    {
        $this->asset();

        // No history at all: the holding is eligible, but nothing happens
        // unless it is asked for.
        $preview = $this->preview($this->snapshot())->assertOk();
        $row = $preview->json('data.snapshot.positions.0');

        $this->assertTrue($row['opening_position_eligible']);
        $this->assertSame('skipped', $row['import_status']);
        $this->assertStringContainsString('Enable the opening-position fallback', $row['reason']);

        $this->commit($this->snapshot())->assertOk();
        $this->assertSame(0, Position::query()->count());
    }

    public function test_an_opted_in_opening_position_is_marked_synthetic(): void
    {
        $this->asset();

        $this->commit($this->snapshot(), ['create_snapshot_positions' => true])->assertOk();

        $position = Position::query()->sole();

        $this->assertSame(Position::SOURCE_STOCKBIT_SNAPSHOT, $position->source);
        $this->assertSame('entry', $position->side);
        $this->assertEqualsWithDelta(11_500.0, (float) $position->qty_shares, 0.0001);
        $this->assertEqualsWithDelta(1663.3609, (float) $position->price, 0.0001);
        // The broker average already embeds its fees; charging one again here
        // would double-count them.
        $this->assertSame(0.0, (float) $position->fee_value);
    }

    public function test_an_opening_position_is_not_created_twice(): void
    {
        $this->asset();

        $this->commit($this->snapshot(), ['create_snapshot_positions' => true])->assertOk();
        $this->assertSame(1, Position::query()->count());

        $second = $this->commit($this->snapshot(), ['create_snapshot_positions' => true])->assertOk();

        $this->assertSame(1, Position::query()->count());
        $this->assertSame(
            'skipped_duplicate',
            $second->json('data.snapshot.positions.0.import_status'),
        );
    }

    public function test_a_share_count_mismatch_is_reported_as_a_warning(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        // The broker says 12,000; the ledger computes 11,500.
        $response = $this->preview($this->snapshot(shares: 12_000))->assertOk();

        $row = $response->json('data.snapshot.positions.0');
        $this->assertFalse($row['shares_match']);
        $this->assertNotEmpty($response->json('data.warnings'));
        $this->assertStringContainsString('12,000', $response->json('data.warnings.0'));
    }

    public function test_cash_reconciliation_proposes_a_base_that_does_not_double_count_dividends(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        // An imported dividend already contributes to the calculated cash.
        CashMovement::create([
            'portfolio_id' => $this->portfolio->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'amount' => 18_745,
            'executed_at' => '2026-07-29 07:38:03',
            'note' => 'BRPT cash dividend',
            'source' => CashMovement::SOURCE_STOCKBIT,
            'external_id' => '440819045',
        ]);

        $cash = $this->preview($this->snapshot())->assertOk()->json('data.snapshot.cash');

        $this->assertEqualsWithDelta(5_989_968.15, (float) $cash['broker_cash'], 0.01);
        $this->assertEqualsWithDelta(18_745.0, (float) $cash['cash_movements_total'], 0.01);
        // Base must be the broker figure MINUS what the movements already add,
        // so the calculated total lands exactly on the broker's number.
        $this->assertEqualsWithDelta(5_971_223.15, (float) $cash['proposed_base_cash'], 0.01);
        $this->assertFalse($cash['already_reconciled']);
    }

    public function test_cash_is_untouched_unless_reconciliation_is_confirmed(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        $this->commit($this->snapshot(), ['create_snapshot_positions' => false])->assertOk();

        $this->assertSame(0.0, (float) $this->portfolio->fresh()->cash_balance);
    }

    public function test_confirmed_cash_reconciliation_makes_the_calculated_cash_match_the_broker(): void
    {
        $asset = $this->asset();
        $this->seedBrptHistory($asset);

        CashMovement::create([
            'portfolio_id' => $this->portfolio->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'amount' => 18_745,
            'executed_at' => '2026-07-29 07:38:03',
            'source' => CashMovement::SOURCE_STOCKBIT,
            'external_id' => '440819045',
        ]);

        $this->commit($this->snapshot(), ['reconcile_cash' => true])->assertOk();

        $this->assertEqualsWithDelta(5_971_223.15, (float) $this->portfolio->fresh()->cash_balance, 0.01);

        // What the user actually sees: base + movements == the broker's cash.
        $summary = $this->getJson("/api/v1/portfolios/{$this->portfolio->id}/summary")->assertOk();
        $this->assertEqualsWithDelta(5_989_968.15, (float) $summary->json('data.cash_balance'), 0.01);
    }

    public function test_a_snapshot_for_a_missing_asset_is_blocking(): void
    {
        $response = $this->preview($this->snapshot())->assertOk();

        $this->assertFalse($response->json('data.can_commit'));
        $this->assertSame(['BRPT'], $response->json('data.missing_assets'));
    }

    public function test_stock_on_hand_is_never_used_as_the_quantity(): void
    {
        $this->asset();

        // stock_on_hand reads zero while the balance is 11,500 — using it
        // would erase a real holding from the comparison.
        $payload = (string) json_encode([
            'data' => [
                'summary' => ['trading' => ['balance' => 1_000_000]],
                'results' => [[
                    'symbol' => 'BRPT',
                    'stock_on_hand' => 0,
                    'qty' => ['balance' => ['lot' => 115, 'share' => 11_500]],
                    'price' => ['latest' => 1840, 'average' => ['price' => 1663.3609]],
                    'asset' => ['unrealised' => ['market_value' => 21_160_000], 'amount_invested' => 19_128_650.35],
                ]],
            ],
        ]);

        $row = $this->preview($payload)->assertOk()->json('data.snapshot.positions.0');

        $this->assertEqualsWithDelta(11_500.0, (float) $row['broker_shares'], 0.0001);
    }
}

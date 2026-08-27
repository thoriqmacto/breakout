<?php

namespace Tests\Feature\Portfolio;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Models\User;
use App\Services\Portfolio\PortfolioCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Stockbit JSON import, end to end through the API.
 *
 * The BRPT figures in these tests are the real ones from the payload this
 * feature was built against, so the arithmetic is checked against a broker's
 * own numbers rather than against numbers this code produced.
 */
class StockbitImportTest extends TestCase
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
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function history(array $entries): string
    {
        return (string) json_encode([
            'message' => 'History Info retrieved',
            'data' => ['history' => [['date' => 'Jun 2026', 'history_list' => $entries]]],
        ]);
    }

    /**
     * The two 2026 BUYs that make up the current BRPT holding.
     *
     * @return array<int, array<string, mixed>>
     */
    private function brptBuys(): array
    {
        return [
            [
                'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 2000, 'lot' => 50,
                'shares' => 5000, 'amount' => 10_000_000, 'fee' => 15_000,
                'netamount' => 10_015_000, 'currency' => 'IDR', 'status' => 'MATCH',
                'date' => '19 May 2026', 'time' => '09:15:00', 'id' => '380000001',
            ],
            [
                'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1400, 'lot' => 65,
                'shares' => 6500, 'amount' => 9_100_000, 'fee' => 13_650,
                'netamount' => 9_113_650, 'currency' => 'IDR', 'status' => 'MATCH',
                'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => '393598293',
            ],
        ];
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

    public function test_preview_detects_history_and_writes_nothing(): void
    {
        $this->asset();

        $response = $this->preview($this->history($this->brptBuys()))->assertOk();

        $this->assertSame('history', $response->json('data.type'));
        $this->assertCount(2, $response->json('data.trades'));
        $this->assertSame(2, $response->json('data.totals.new'));
        $this->assertTrue($response->json('data.can_commit'));

        $this->assertSame(0, Position::query()->count());
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_buy_import_preserves_the_exact_broker_fee(): void
    {
        $asset = $this->asset();

        $this->commit($this->history($this->brptBuys()))->assertOk();

        $first = Position::query()->where('external_id', '380000001')->sole();

        $this->assertSame($asset->id, (int) $first->asset_id);
        $this->assertSame('entry', $first->side);
        $this->assertSame(5000.0, (float) $first->qty_shares);
        $this->assertSame(2000.0, (float) $first->price);
        // Exact money from the broker, never re-derived from a rounded rate.
        $this->assertSame(15_000.0, (float) $first->fee_value);
        // 15,000 / 10,000,000 = 0.15%
        $this->assertEqualsWithDelta(0.15, (float) $first->fee_rate, 0.000001);
        // (10,000,000 + 15,000) / 5,000
        $this->assertEqualsWithDelta(2003.0, (float) $first->avg_price, 0.000001);
        $this->assertSame(Position::SOURCE_STOCKBIT, $first->source);
        $this->assertSame('2026-05-19 09:15:00', $first->executed_at->toDateTimeString());
    }

    public function test_sell_import_preserves_the_exact_broker_fee(): void
    {
        $this->asset();

        $this->commit($this->history([[
            'command' => 'SELL', 'symbol' => 'BRPT', 'price' => 3720, 'shares' => 500,
            'amount' => 1_860_000, 'fee' => 4650, 'netamount' => 1_855_350,
            'status' => 'MATCH', 'date' => '04 Nov 2025', 'time' => '09:31:24',
            'id' => '195103238',
        ]]))->assertOk();

        $position = Position::query()->sole();

        $this->assertSame('exit', $position->side);
        $this->assertSame(4650.0, (float) $position->fee_value);
        // Proceeds net of the fee: (1,860,000 - 4,650) / 500
        $this->assertEqualsWithDelta(3710.7, (float) $position->avg_price, 0.000001);
    }

    /**
     * The headline fixture: two BUYs, one dividend, and the exact holding they
     * produce.
     */
    public function test_the_brpt_fixture_produces_the_exact_cost_basis(): void
    {
        $this->asset();

        $entries = $this->brptBuys();
        $entries[] = [
            'command' => 'DIV', 'symbol' => 'BRPT', 'price' => 1.63, 'shares' => 11_500,
            'amount' => 18_745, 'fee' => 0, 'netamount' => 18_745, 'status' => 'Success',
            'date' => '29 Jul 2026', 'time' => '07:38:03',
            'dividend_per_share' => 1.63, 'dividend_type' => 'Cash', 'id' => '440819045',
        ];

        $this->commit($this->history($entries))->assertOk();

        $summary = app(PortfolioCalculator::class)->compute(
            $this->portfolio->fresh()->load(['positions.asset.latestPriceRecord', 'cashMovements'])
        );

        $holding = collect($summary['holdings'])->firstWhere('symbol', 'BRPT');

        $this->assertSame(11_500.0, $holding['qty']);
        $this->assertEqualsWithDelta(19_128_650.0, $holding['cost_basis'], 0.01);
        $this->assertEqualsWithDelta(1663.36087, $holding['avg_cost'], 0.0001);

        // The dividend is cash, not a position.
        $this->assertSame(2, Position::query()->count());
        $movement = CashMovement::query()->sole();
        $this->assertSame(CashMovement::KIND_DIVIDEND, $movement->kind);
        $this->assertSame(18_745.0, (float) $movement->amount);
        $this->assertSame('BRPT cash dividend — Rp1.63/share', $movement->note);
        $this->assertSame(18_745.0, $summary['cash_balance']);
    }

    public function test_a_full_round_trip_realizes_profit_net_of_both_fees(): void
    {
        $this->asset();

        // Buy 1,000 @ 1,000 with a 1,500 fee, sell 1,000 @ 1,200 with a 1,800
        // fee. Realized = (1,200 - 1,001.5) * 1,000 - 1,800 = 196,700.
        $this->commit($this->history([
            [
                'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1000, 'shares' => 1000,
                'amount' => 1_000_000, 'fee' => 1500, 'netamount' => 1_001_500,
                'status' => 'MATCH', 'date' => '03 Feb 2025', 'time' => '09:05:00', 'id' => 'rt-1',
            ],
            [
                'command' => 'SELL', 'symbol' => 'BRPT', 'price' => 1200, 'shares' => 1000,
                'amount' => 1_200_000, 'fee' => 1800, 'netamount' => 1_198_200,
                'status' => 'MATCH', 'date' => '10 Mar 2025', 'time' => '10:00:00', 'id' => 'rt-2',
            ],
        ]))->assertOk();

        $summary = app(PortfolioCalculator::class)->compute(
            $this->portfolio->fresh()->load(['positions.asset.latestPriceRecord', 'cashMovements'])
        );

        $this->assertEqualsWithDelta(196_700.0, $summary['realized_pl'], 0.01);
    }

    public function test_re_importing_the_same_payload_creates_nothing(): void
    {
        $this->asset();
        $payload = $this->history($this->brptBuys());

        $this->commit($payload)->assertOk();
        $this->assertSame(2, Position::query()->count());

        // Re-pasting is a legitimate thing to do, so it succeeds and reports
        // that there was nothing to do -- it does not fail.
        $second = $this->commit($payload)->assertOk();

        $this->assertSame(2, Position::query()->count(), 'A re-import must not duplicate anything.');
        $this->assertSame(0, $second->json('data.created.positions'));
        $this->assertStringContainsString('Nothing new to import', $second->json('message'));

        $statuses = collect($second->json('data.trades'))->pluck('import_status')->all();
        $this->assertSame(['skipped_duplicate', 'skipped_duplicate'], $statuses);
    }

    public function test_a_duplicate_dividend_is_not_imported_twice(): void
    {
        $this->asset();

        $payload = $this->history([[
            'command' => 'DIV', 'symbol' => 'BRPT', 'price' => 1.63, 'shares' => 11_500,
            'amount' => 18_745, 'fee' => 0, 'netamount' => 18_745, 'status' => 'Success',
            'date' => '29 Jul 2026', 'time' => '07:38:03',
            'dividend_per_share' => 1.63, 'id' => '440819045',
        ]]);

        $this->commit($payload)->assertOk();
        $this->commit($payload)->assertOk();

        $this->assertSame(1, CashMovement::query()->count());
    }

    public function test_two_fills_on_the_same_day_keep_their_true_order(): void
    {
        $this->asset();

        // Same date, different times, and deliberately pasted newest-first so
        // insertion order would give the wrong answer.
        $this->commit($this->history([
            [
                'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1200, 'shares' => 1000,
                'amount' => 1_200_000, 'fee' => 0, 'netamount' => 1_200_000,
                'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '14:30:00', 'id' => 'late',
            ],
            [
                'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1000, 'shares' => 1000,
                'amount' => 1_000_000, 'fee' => 0, 'netamount' => 1_000_000,
                'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '09:00:00', 'id' => 'early',
            ],
        ]))->assertOk();

        $ordered = Position::query()->orderBy('executed_at')->pluck('external_id')->all();
        $this->assertSame(['early', 'late'], $ordered);

        $early = Position::query()->where('external_id', 'early')->sole();
        $this->assertSame('2026-06-08 09:00:00', $early->executed_at->toDateTimeString());
        $late = Position::query()->where('external_id', 'late')->sole();
        $this->assertSame('2026-06-08 14:30:00', $late->executed_at->toDateTimeString());
    }

    public function test_a_missing_asset_blocks_the_import(): void
    {
        // No asset created at all.
        $response = $this->preview($this->history($this->brptBuys()))->assertOk();

        $this->assertFalse($response->json('data.can_commit'));
        $this->assertSame(['BRPT'], $response->json('data.missing_assets'));
        $this->assertStringContainsString('Missing asset: BRPT', $response->json('data.errors.0.reason'));

        $this->commit($this->history($this->brptBuys()))->assertStatus(422);
        $this->assertSame(0, Position::query()->count());
    }

    public function test_unmatched_orders_are_skipped_rather_than_imported(): void
    {
        $this->asset();

        $response = $this->preview($this->history([[
            'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1400, 'shares' => 6500,
            'amount' => 9_100_000, 'fee' => 13_650, 'netamount' => 9_113_650,
            'status' => 'CANCELLED', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => 'x1',
        ]]))->assertOk();

        $this->assertSame(1, $response->json('data.totals.skipped'));
        $this->assertSame(0, $response->json('data.totals.new'));
        $this->assertStringContainsString('not a completed transaction', $response->json('data.skipped.0.reason'));
    }

    public function test_unsupported_commands_appear_as_skipped_with_their_details(): void
    {
        $this->asset();

        $response = $this->preview($this->history([[
            'command' => 'RIGHTS', 'symbol' => 'BRPT', 'price' => 100, 'shares' => 500,
            'amount' => 50_000, 'fee' => 0, 'netamount' => 50_000, 'status' => 'MATCH',
            'date' => '01 Jul 2026', 'time' => '10:00:00', 'id' => 'rights-1',
        ]]))->assertOk();

        $row = $response->json('data.skipped.0');

        $this->assertSame('skipped', $row['import_status']);
        $this->assertSame('RIGHTS', $row['command']);
        $this->assertSame('BRPT', $row['symbol']);
        $this->assertSame('rights-1', $row['external_id']);
        $this->assertStringContainsString('Unsupported command', $row['reason']);
    }

    public function test_status_matching_is_case_insensitive(): void
    {
        $this->asset();

        $response = $this->preview($this->history([[
            'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1400, 'shares' => 6500,
            'amount' => 9_100_000, 'fee' => 13_650, 'netamount' => 9_113_650,
            'status' => 'match', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => 'lower',
        ]]))->assertOk();

        $this->assertSame(1, $response->json('data.totals.new'));
    }

    public function test_a_netamount_that_disagrees_with_the_fee_is_warned_about(): void
    {
        $this->asset();

        $response = $this->preview($this->history([[
            'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 1400, 'shares' => 6500,
            'amount' => 9_100_000, 'fee' => 13_650,
            // Deliberately wrong: should be 9,113,650.
            'netamount' => 9_999_999,
            'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => 'odd',
        ]]))->assertOk();

        $this->assertNotEmpty($response->json('data.warnings'));
        $this->assertStringContainsString('netamount', $response->json('data.warnings.0'));
        // A warning, not a blocker: price, quantity and fee are all we need.
        $this->assertTrue($response->json('data.can_commit'));
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->asset();

        $this->preview('{not json')->assertStatus(422)->assertJsonPath('status', 'error');
        $this->commit('{not json')->assertStatus(422);
        $this->assertSame(0, Position::query()->count());
    }

    public function test_an_unrecognised_payload_shape_is_rejected(): void
    {
        $this->preview('{"message":"ok","data":{"something_else":[]}}')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_import_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/portfolios/{$this->portfolio->id}/imports/stockbit/preview", [
            'payload' => $this->history($this->brptBuys()),
        ])->assertStatus(401);
    }

    public function test_a_stranger_cannot_import_into_someone_elses_portfolio(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, ['*']);

        $this->asset();

        $this->preview($this->history($this->brptBuys()))->assertStatus(403);
        $this->commit($this->history($this->brptBuys()))->assertStatus(403);
        $this->assertSame(0, Position::query()->count());
    }

    public function test_a_2025_trade_survives_a_2026_portfolio_view(): void
    {
        $this->asset();

        $entries = $this->brptBuys();
        $entries[] = [
            'command' => 'BUY', 'symbol' => 'BRPT', 'price' => 900, 'shares' => 1000,
            'amount' => 900_000, 'fee' => 1350, 'netamount' => 901_350,
            'status' => 'MATCH', 'date' => '15 Aug 2025', 'time' => '09:30:00', 'id' => 'hist-2025',
        ];

        $this->commit($this->history($entries))->assertOk();

        // The portfolio's presentation year is 2026.
        $this->assertSame(2026, (int) $this->portfolio->fresh()->year);

        // The default year-filtered view hides it...
        $filtered = $this->getJson("/api/v1/portfolios/{$this->portfolio->id}/positions")->assertOk();
        $this->assertCount(2, $filtered->json('data'));

        // ...but the ledger still owns it, and "all" reaches it.
        $all = $this->getJson("/api/v1/portfolios/{$this->portfolio->id}/positions?year=all")->assertOk();
        $this->assertCount(3, $all->json('data'));

        // And the calculator has always read the whole ledger, so the 2025
        // trade is part of the cost basis regardless of the view.
        $summary = $this->getJson("/api/v1/portfolios/{$this->portfolio->id}/summary")->assertOk();
        $holding = collect($summary->json('data.holdings'))->firstWhere('symbol', 'BRPT');
        // JSON renders a whole number without a decimal point, so compare
        // numerically rather than by type.
        $this->assertEqualsWithDelta(12_500.0, (float) $holding['qty'], 0.0001);
    }

    public function test_the_position_resource_exposes_the_execution_timestamp(): void
    {
        $this->asset();
        $this->commit($this->history($this->brptBuys()))->assertOk();

        $row = $this->getJson("/api/v1/portfolios/{$this->portfolio->id}/positions?year=all")
            ->assertOk()
            ->json('data.0');

        $this->assertSame('2026-05-19', $row['executed_at']);
        $this->assertStringStartsWith('2026-05-19T09:15:00', $row['executed_at_iso']);
        $this->assertSame('stockbit', $row['source']);
        $this->assertSame('380000001', $row['external_id']);
    }
}

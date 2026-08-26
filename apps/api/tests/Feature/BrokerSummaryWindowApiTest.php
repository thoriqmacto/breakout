<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrokerSummaryWindowApiTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->asset = Asset::create(['symbol' => 'BRPT', 'name' => 'Barito']);
    }

    private function window(string $from, string $to, int $returnedBuyers = 2, ?int $totalBuyers = 42): BrokerSummaryWindow
    {
        $window = BrokerSummaryWindow::create([
            'asset_id' => $this->asset->id,
            'from_date' => $from,
            'to_date' => $to,
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'returned_buyer_count' => $returnedBuyers,
            'returned_seller_count' => 1,
            'total_buyer' => $totalBuyers,
            'total_seller' => 44,
        ]);

        $window->entries()->create([
            'broker_code' => 'ZP',
            'side' => BrokerSummaryEntry::SIDE_BUY,
            'broker_type' => 'Asing',
            'frequency' => 84391,
            'source_date' => $from,
            'net_lot' => 2707204,
            'net_value' => 506405114500,
            'gross_volume' => 900209200,
            'gross_value' => 1591645006000,
            'average_price' => 1768.083470,
        ]);

        $window->entries()->create([
            'broker_code' => 'CC',
            'side' => BrokerSummaryEntry::SIDE_SELL,
            'broker_type' => 'Lokal',
            'frequency' => 51204,
            'source_date' => $from,
            'net_lot' => -1204512,
            'net_value' => -225104400000,
            'gross_volume' => 411322000,
            'gross_value' => 722011400000,
            'average_price' => 1755.221,
        ]);

        return $window;
    }

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/broker-summary/windows')->assertUnauthorized();
    }

    public function test_it_lists_windows_with_their_range_and_coverage(): void
    {
        $this->window('2026-05-26', '2026-08-26');

        $response = $this->getJson('/api/v1/broker-summary/windows?symbol=BRPT')->assertOk();

        $response
            ->assertJsonPath('data.windows.0.from_date', '2026-05-26')
            ->assertJsonPath('data.windows.0.to_date', '2026-08-26')
            ->assertJsonPath('data.windows.0.is_single_day', false)
            ->assertJsonPath('data.windows.0.symbol', 'BRPT')
            ->assertJsonPath('data.windows.0.coverage.returned_buyer_count', 2)
            ->assertJsonPath('data.windows.0.coverage.total_buyer', 42)
            ->assertJsonPath('data.windows.0.coverage.buyers_truncated', true);
    }

    public function test_a_single_day_window_is_flagged_as_such(): void
    {
        $this->window('2026-08-26', '2026-08-26');

        $this->getJson('/api/v1/broker-summary/windows?symbol=BRPT')
            ->assertOk()
            ->assertJsonPath('data.windows.0.is_single_day', true);
    }

    /**
     * Two aggregates sharing a start date are distinct and must both be
     * listed; the old model collapsed them onto one date.
     */
    public function test_both_windows_sharing_a_start_date_are_listed(): void
    {
        $this->window('2026-05-26', '2026-08-26');
        $this->window('2026-05-26', '2026-09-26');

        $this->getJson('/api/v1/broker-summary/windows?symbol=BRPT')
            ->assertOk()
            ->assertJsonCount(2, 'data.windows');
    }

    /**
     * Exact is the default because picking out one imported aggregate is the
     * common case; overlap is a different question and has to be asked for.
     */
    public function test_exact_matching_selects_one_window(): void
    {
        $this->window('2026-05-26', '2026-08-26');
        $this->window('2026-05-26', '2026-09-26');

        $this->getJson('/api/v1/broker-summary/windows?symbol=BRPT&window_from=2026-05-26&window_to=2026-08-26')
            ->assertOk()
            ->assertJsonCount(1, 'data.windows')
            ->assertJsonPath('data.windows.0.to_date', '2026-08-26');
    }

    public function test_overlap_matching_returns_every_intersecting_window(): void
    {
        $this->window('2026-05-26', '2026-08-26');
        $this->window('2026-05-26', '2026-09-26');
        $this->window('2020-01-01', '2020-02-01');

        $this->getJson(
            '/api/v1/broker-summary/windows?symbol=BRPT&window_from=2026-07-01&window_to=2026-07-31&match=overlap'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.windows');
    }

    public function test_showing_a_window_returns_its_buyers_sellers_and_detector_shape(): void
    {
        $window = $this->window('2026-05-26', '2026-08-26');

        $response = $this->getJson("/api/v1/broker-summary/windows/{$window->id}")->assertOk();

        $response
            ->assertJsonPath('data.window.buyers.0.broker_code', 'ZP')
            ->assertJsonPath('data.window.buyers.0.broker_type', 'Asing')
            ->assertJsonPath('data.window.buyers.0.frequency', 84391)
            ->assertJsonPath('data.window.buyers.0.source_date', '2026-05-26')
            ->assertJsonPath('data.window.sellers.0.broker_code', 'CC')
            ->assertJsonPath('data.window.sellers.0.net_lot', -1204512);

        // Net and gross are distinct fields, not one derived from the other.
        $buyer = $response->json('data.window.buyers.0');
        $this->assertNotSame($buyer['net_value'], $buyer['gross_value']);
        $this->assertNotSame($buyer['net_lot'], $buyer['gross_volume']);
    }

    public function test_entries_can_be_filtered_by_side_and_broker(): void
    {
        $this->window('2026-05-26', '2026-08-26');

        $this->getJson('/api/v1/broker-summary/entries?symbol=BRPT&side=sell')
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.broker_code', 'CC');

        $this->getJson('/api/v1/broker-summary/entries?symbol=BRPT&broker=zp')
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.broker_code', 'ZP');

        $this->getJson('/api/v1/broker-summary/entries?symbol=BRPT&broker_type=Asing')
            ->assertOk()
            ->assertJsonCount(1, 'data.entries');
    }

    public function test_entries_carry_their_window_range(): void
    {
        $this->window('2026-05-26', '2026-08-26');

        $this->getJson('/api/v1/broker-summary/entries?symbol=BRPT&side=buy')
            ->assertOk()
            ->assertJsonPath('data.entries.0.window.from_date', '2026-05-26')
            ->assertJsonPath('data.entries.0.window.to_date', '2026-08-26')
            ->assertJsonPath('data.entries.0.window.is_single_day', false);
    }

    public function test_entries_are_paginated_server_side(): void
    {
        $this->window('2026-05-26', '2026-08-26');

        $response = $this->getJson('/api/v1/broker-summary/entries?symbol=BRPT&per_page=1')->assertOk();

        $response
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.last_page', 2);
    }

    public function test_an_unknown_total_is_not_reported_as_complete(): void
    {
        $this->window('2026-05-26', '2026-08-26', returnedBuyers: 2, totalBuyers: null);

        $this->getJson('/api/v1/broker-summary/windows?symbol=BRPT')
            ->assertOk()
            ->assertJsonPath('data.windows.0.coverage.total_buyer', null)
            ->assertJsonPath('data.windows.0.coverage.buyers_truncated', false);
    }
}

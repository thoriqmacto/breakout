<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use App\Services\BrokerSummaryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import semantics for broker summaries.
 *
 * A Stockbit response fetched with from/to is one aggregate for that whole
 * range. The previous version of this test asserted the opposite: it stamped
 * every broker row with data.from as trade_date -- a date that was not even
 * inside the filename's own range -- and so encoded the bug rather than
 * catching it.
 */
class BrokerSummaryImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The BRPT payload shape, from a real response.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $from, string $to, array $overrides = []): array
    {
        return array_replace_recursive([
            'data' => [
                'from' => $from,
                'to' => $to,
                'broker_summary' => [
                    'symbol' => 'BRPT',
                    'transaction_type' => 'TRANSACTION_TYPE_NET',
                    'brokers_buy' => [[
                        'netbs_broker_code' => 'ZP',
                        'netbs_date' => '20260526',
                        'netbs_stock_code' => 'BRPT',
                        'type' => 'Asing',
                        'freq' => '84391',
                        'blot' => '2.707204e+06',
                        'blotv' => '9.002092e+08',
                        'bval' => '5.064051145e+11',
                        'bvalv' => '1.591645006e+12',
                        'netbs_buy_avg_price' => '1768.0834699312115',
                    ]],
                    'brokers_sell' => [[
                        'netbs_broker_code' => 'CC',
                        'netbs_date' => '20260526',
                        'netbs_stock_code' => 'BRPT',
                        'type' => 'Lokal',
                        'freq' => '51204',
                        'slot' => '-1.204512e+06',
                        'slotv' => '4.113220e+08',
                        'sval' => '-2.251044e+11',
                        'svalv' => '7.220114e+11',
                        'netbs_sell_avg_price' => '1755.2210000000000',
                    ]],
                ],
                'bandar_detector' => [
                    'broker_accdist' => 'Acc',
                    'number_broker_buysell' => -2,
                    'total_buyer' => 42,
                    'total_seller' => 44,
                    'value' => 688247200000,
                    'volume' => 2967107,
                    'average' => 2319.5903,
                    'avg' => ['accdist' => 'Neutral', 'amount' => 23235537000, 'percent' => 3.376045, 'vol' => 100170.87],
                    'top10' => ['accdist' => 'Small Acc', 'amount' => 60374757000, 'percent' => 8.772248, 'vol' => 260282],
                ],
            ],
        ], $overrides);
    }

    private function store(string $from, string $to, array $overrides = [], string $symbol = 'BRPT'): void
    {
        Storage::disk('local')->put(
            "broker_summary/{$symbol}_{$from}_{$to}_TRANSACTION_TYPE_NET.json",
            json_encode($this->payload($from, $to, $overrides)),
        );
    }

    private function import(): array
    {
        return app(BrokerSummaryImporter::class)->importFromDisk('local', 'broker_summary');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_ranged_response_is_stored_as_one_window(): void
    {
        $this->store('2026-05-26', '2026-08-26');

        $this->assertSame(1, $this->import()['file_count']);

        $window = BrokerSummaryWindow::first();

        $this->assertNotNull($window);
        $this->assertSame('2026-05-26', $window->from_date->toDateString());
        $this->assertSame('2026-08-26', $window->to_date->toDateString(), 'The range end was lost.');
        $this->assertFalse($window->isSingleDay());
        $this->assertSame('TRANSACTION_TYPE_NET', $window->transaction_type);
        $this->assertSame(
            Asset::where('symbol', 'BRPT')->value('id'),
            $window->asset_id,
        );
    }

    /**
     * netbs_date repeats the range start on every row. It is kept for auditing
     * and must never stand in for the window's end.
     */
    public function test_the_source_date_is_kept_but_does_not_become_the_range(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $entry = BrokerSummaryEntry::where('broker_code', 'ZP')->first();

        $this->assertSame('2026-05-26', $entry->source_date->toDateString());
        $this->assertSame('2026-08-26', $entry->window->to_date->toDateString());
        $this->assertNotSame(
            $entry->source_date->toDateString(),
            $entry->window->to_date->toDateString(),
        );
    }

    public function test_it_maps_net_and_gross_separately_and_keeps_the_real_keys(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $buyer = BrokerSummaryEntry::where('broker_code', 'ZP')->first();

        $this->assertSame('buy', $buyer->side);
        $this->assertSame('Asing', $buyer->broker_type, 'The real "type" key was not read.');
        $this->assertSame(84391, $buyer->frequency, 'The real "freq" key was not read.');

        // net from blot/bval, gross from blotv/bvalv -- never conflated.
        $this->assertSame(2707204, $buyer->net_lot);
        $this->assertEqualsWithDelta(5.064051145e+11, $buyer->net_value, 1.0);
        $this->assertSame(900209200, $buyer->gross_volume);
        $this->assertEqualsWithDelta(1.591645006e+12, $buyer->gross_value, 1.0);
        $this->assertEqualsWithDelta(1768.08347, $buyer->average_price, 0.0001);

        $seller = BrokerSummaryEntry::where('broker_code', 'CC')->first();

        $this->assertSame('sell', $seller->side);
        $this->assertSame('Lokal', $seller->broker_type);
        $this->assertSame(51204, $seller->frequency);
        $this->assertSame(-1204512, $seller->net_lot, 'The source sign was discarded.');
        $this->assertEqualsWithDelta(-2.251044e+11, $seller->net_value, 1.0);
    }

    /**
     * The net figures are Stockbit's own range-level classification. Deriving
     * them as buy_value - sell_value across the two lists treats them as
     * independent legs, which they are not.
     */
    public function test_net_values_are_not_recomputed_from_the_two_lists(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $buyer = BrokerSummaryEntry::where('broker_code', 'ZP')->first();
        $seller = BrokerSummaryEntry::where('broker_code', 'CC')->first();

        $this->assertEqualsWithDelta(5.064051145e+11, $buyer->net_value, 1.0);
        $this->assertNotEqualsWithDelta(
            5.064051145e+11 - 2.251044e+11,
            $buyer->net_value,
            1.0,
        );
        $this->assertEqualsWithDelta(-2.251044e+11, $seller->net_value, 1.0);
    }

    /**
     * A buyer-list blot can be negative in real payloads. Forcing it positive
     * to satisfy an unsigned column would replace the source's meaning with
     * the array's name.
     */
    public function test_a_negative_value_on_the_buy_list_survives(): void
    {
        $this->store('2026-05-26', '2026-08-26', [
            'data' => ['broker_summary' => ['brokers_buy' => [['blot' => '-5000']]]],
        ]);
        $this->import();

        $this->assertSame(-5000, BrokerSummaryEntry::where('broker_code', 'ZP')->value('net_lot'));
    }

    /**
     * The bug that made this redesign necessary: two windows sharing a start
     * date collided on (asset, trade_date, broker, transaction_type) and one
     * silently replaced the other.
     */
    public function test_two_windows_with_the_same_start_but_different_ends_coexist(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $this->store('2026-05-26', '2026-09-26');
        $this->import();

        $this->assertSame(2, BrokerSummaryWindow::count(), 'One window overwrote the other.');
        $this->assertSame(1, BrokerSummaryWindow::where('to_date', '2026-08-26')->count());
        $this->assertSame(1, BrokerSummaryWindow::where('to_date', '2026-09-26')->count());
    }

    public function test_reimporting_the_same_window_is_idempotent(): void
    {
        $this->store('2026-05-26', '2026-08-26');

        $this->import();
        $this->import();
        $this->import();

        $this->assertSame(1, BrokerSummaryWindow::count());
        $this->assertSame(2, BrokerSummaryEntry::count(), 'Re-import duplicated the entries.');
    }

    public function test_a_single_day_range_is_a_normal_window(): void
    {
        $this->store('2026-08-26', '2026-08-26');
        $this->import();

        $window = BrokerSummaryWindow::first();

        $this->assertTrue($window->isSingleDay());
        $this->assertSame('2026-08-26', $window->from_date->toDateString());
        $this->assertSame('2026-08-26', $window->to_date->toDateString());
    }

    /**
     * Stockbit caps each list at the requested limit while still reporting the
     * true totals, so a stored window can hold 1 of 42 buyers. Presenting that
     * as the complete broker list would be wrong.
     */
    public function test_a_truncated_broker_list_is_detected(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $window = BrokerSummaryWindow::first();

        $this->assertSame(1, $window->returned_buyer_count);
        $this->assertSame(42, $window->total_buyer);
        $this->assertTrue($window->buyersTruncated());
        $this->assertSame(44, $window->total_seller);
        $this->assertTrue($window->sellersTruncated());
    }

    public function test_complete_coverage_is_not_reported_as_truncated(): void
    {
        $this->store('2026-05-26', '2026-08-26', [
            'data' => ['bandar_detector' => ['total_buyer' => 1, 'total_seller' => 1]],
        ]);
        $this->import();

        $window = BrokerSummaryWindow::first();

        $this->assertFalse($window->buyersTruncated());
        $this->assertFalse($window->sellersTruncated());
    }

    public function test_the_detector_is_linked_to_its_window_and_keeps_unknown_metrics(): void
    {
        $this->store('2026-05-26', '2026-08-26', [
            'data' => ['bandar_detector' => ['brand_new_metric' => ['accdist' => 'Dist']]],
        ]);
        $this->import();

        $window = BrokerSummaryWindow::first();
        $detector = $window->bandarDetectorSummary;

        $this->assertNotNull($detector, 'The detector was not linked to the window.');
        $this->assertSame('2026-05-26', $detector->from_date->toDateString());
        $this->assertSame('2026-08-26', $detector->to_date->toDateString());
        $this->assertArrayHasKey('avg', $detector->metrics_json);
        $this->assertArrayHasKey('top10', $detector->metrics_json);
        $this->assertArrayHasKey(
            'brand_new_metric',
            $detector->metrics_json,
            'An unrecognised metric group was discarded.',
        );
    }

    /**
     * The legacy tables carry one date and cannot express a range. Writing a
     * three-month aggregate into broksums.date is the fiction being removed.
     */
    public function test_legacy_tables_are_only_written_for_a_single_day_window(): void
    {
        $this->store('2026-05-26', '2026-08-26');
        $this->import();

        $this->assertDatabaseCount('broksums', 0);
        $this->assertDatabaseCount('broker_summary_facts', 0);

        Storage::disk('local')->delete(
            'broker_summary/BRPT_2026-05-26_2026-08-26_TRANSACTION_TYPE_NET.json'
        );
        $this->store('2026-08-26', '2026-08-26');
        $this->import();

        $this->assertDatabaseHas('broksums', ['date' => '2026-08-26']);
    }
}

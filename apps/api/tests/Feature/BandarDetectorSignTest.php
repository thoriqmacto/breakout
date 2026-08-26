<?php

namespace Tests\Feature;

use App\Models\BandarDetectorSummary;
use App\Services\BrokerSummaryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * number_broker_buysell is buyers minus sellers, so it is signed.
 *
 * It was declared unsignedInteger, and MariaDB rejected a perfectly ordinary
 * -27 (29 buyers, 56 sellers) with SQLSTATE[22003] 1264. That is the same
 * error buy_lot produced, but the opposite fix: taking abs() here would turn
 * "27 more sellers than buyers" into its opposite, so the column type was
 * wrong rather than the value.
 *
 * These tests pin which columns the importer treats as magnitudes, because
 * that distinction is the whole point and is easy to get backwards. SQLite
 * does not enforce signedness, so the schema itself cannot be asserted here --
 * the guard's column list is what is checked.
 */
class BandarDetectorSignTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $detector
     */
    private function importWith(array $detector): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'broker_summary/BBCA_2026-05-26_2026-08-26.json',
            json_encode([
                'data' => [
                    'broker_summary' => [
                        'brokers_buy' => [[
                            'netbs_broker_code' => 'AB', 'netbs_date' => '2026-08-26', 'blot' => 100,
                        ]],
                        'brokers_sell' => [[
                            'netbs_broker_code' => 'AB', 'netbs_date' => '2026-08-26', 'slot' => -400,
                        ]],
                    ],
                    'bandar_detector' => $detector,
                ],
            ]),
        );

        app(BrokerSummaryImporter::class)->importFromDisk('local', 'broker_summary');
    }

    public function test_a_negative_net_of_buyers_and_sellers_is_kept_as_is(): void
    {
        $this->importWith([
            'broker_accdist' => 'Acc',
            'number_broker_buysell' => -27,
            'total_buyer' => 29,
            'total_seller' => 56,
            'volume' => 2967107,
        ]);

        $row = BandarDetectorSummary::first();

        $this->assertNotNull($row, 'The detector summary was not imported.');
        $this->assertSame(-27, (int) $row->number_broker_buysell, 'The sign was discarded.');
        $this->assertSame(29, (int) $row->total_buyer);
        $this->assertSame(56, (int) $row->total_seller);
    }

    public function test_a_positive_net_is_also_kept(): void
    {
        $this->importWith([
            'number_broker_buysell' => 14,
            'total_buyer' => 40,
            'total_seller' => 26,
        ]);

        $this->assertSame(14, (int) BandarDetectorSummary::first()->number_broker_buysell);
    }

    /**
     * The other side of the decision: broker counts genuinely cannot be
     * negative, so a negative one is bad data and must be reported rather than
     * quietly stored or abs()'d into something plausible.
     */
    public function test_a_negative_broker_count_is_reported_with_its_window(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('total_buyer');

        $this->importWith([
            'number_broker_buysell' => -27,
            'total_buyer' => -29,
            'total_seller' => 56,
        ]);
    }

    public function test_the_failure_names_the_symbol_and_the_window(): void
    {
        try {
            $this->importWith([
                'number_broker_buysell' => 1,
                'total_seller' => -5,
            ]);
            $this->fail('Expected a negative broker count to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BBCA', $e->getMessage());
            $this->assertStringContainsString('2026-05-26', $e->getMessage());
            $this->assertStringContainsString('2026-08-26', $e->getMessage());
        }
    }
}

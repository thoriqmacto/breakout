<?php

namespace Tests\Unit\Services\Portfolio\Import;

use App\Services\Portfolio\Import\StockbitPayloadParser;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Shape handling only: what the parser makes of a payload, with no database
 * and no import decisions involved.
 */
class StockbitPayloadParserTest extends TestCase
{
    private StockbitPayloadParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new StockbitPayloadParser;
    }

    public function test_it_detects_a_history_payload(): void
    {
        $payload = $this->parser->decode('{"data":{"history":[]}}');

        $this->assertSame(StockbitPayloadParser::TYPE_HISTORY, $this->parser->detectType($payload));
    }

    public function test_it_detects_a_snapshot_payload(): void
    {
        $payload = $this->parser->decode('{"data":{"results":[]}}');

        $this->assertSame(StockbitPayloadParser::TYPE_SNAPSHOT, $this->parser->detectType($payload));
    }

    public function test_it_tolerates_a_payload_pasted_without_its_data_envelope(): void
    {
        $this->assertSame(
            StockbitPayloadParser::TYPE_HISTORY,
            $this->parser->detectType($this->parser->decode('{"history":[]}')),
        );
    }

    public function test_an_unrecognised_shape_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unrecognised Stockbit payload/');

        $this->parser->detectType($this->parser->decode('{"data":{"nope":1}}'));
    }

    public function test_malformed_json_is_rejected_with_a_usable_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $this->parser->decode('{oops');
    }

    public function test_an_empty_paste_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->decode('   ');
    }

    public function test_history_rows_are_flattened_and_ordered_oldest_first(): void
    {
        // Stockbit groups by month and lists newest first.
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [
                ['date' => 'Jun 2026', 'history_list' => [
                    ['command' => 'BUY', 'symbol' => 'BRPT', 'shares' => 100, 'price' => 1, 'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => 'c'],
                ]],
                ['date' => 'May 2026', 'history_list' => [
                    ['command' => 'BUY', 'symbol' => 'BRPT', 'shares' => 100, 'price' => 1, 'status' => 'MATCH', 'date' => '19 May 2026', 'time' => '14:00:00', 'id' => 'b'],
                    ['command' => 'BUY', 'symbol' => 'BRPT', 'shares' => 100, 'price' => 1, 'status' => 'MATCH', 'date' => '19 May 2026', 'time' => '09:00:00', 'id' => 'a'],
                ]],
            ]],
        ])));

        $this->assertSame(['a', 'b', 'c'], array_column($rows, 'external_id'));
    }

    public function test_a_date_and_time_are_combined_into_a_timestamp(): void
    {
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [['history_list' => [
                ['command' => 'BUY', 'symbol' => 'BRPT', 'status' => 'MATCH', 'date' => '08 Jun 2026', 'time' => '09:00:34', 'id' => '1'],
            ]]]],
        ])));

        $this->assertSame('2026-06-08 09:00:34', $rows[0]['executed_at']);
    }

    public function test_a_missing_time_falls_back_to_midnight(): void
    {
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [['history_list' => [
                ['command' => 'BUY', 'symbol' => 'BRPT', 'status' => 'MATCH', 'date' => '08 Jun 2026', 'id' => '1'],
            ]]]],
        ])));

        $this->assertSame('2026-06-08 00:00:00', $rows[0]['executed_at']);
    }

    public function test_completion_and_support_are_flagged_per_row(): void
    {
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [['history_list' => [
                ['command' => 'BUY', 'symbol' => 'A', 'status' => 'MATCH', 'date' => '01 Jan 2026', 'id' => '1'],
                ['command' => 'buy', 'symbol' => 'B', 'status' => 'Success', 'date' => '02 Jan 2026', 'id' => '2'],
                ['command' => 'BUY', 'symbol' => 'C', 'status' => 'CANCELLED', 'date' => '03 Jan 2026', 'id' => '3'],
                ['command' => 'RIGHTS', 'symbol' => 'D', 'status' => 'MATCH', 'date' => '04 Jan 2026', 'id' => '4'],
            ]]]],
        ])));

        $this->assertSame([true, true, false, true], array_column($rows, 'completed'));
        $this->assertSame([true, true, true, false], array_column($rows, 'supported'));
        // Lower-case commands are normalised.
        $this->assertSame('BUY', $rows[1]['command']);
    }

    public function test_a_dividend_row_keeps_its_per_share_figure(): void
    {
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [['history_list' => [
                [
                    'command' => 'DIV', 'symbol' => 'BRPT', 'price' => 1.63, 'shares' => 11_500,
                    'amount' => 18_745, 'fee' => 0, 'netamount' => 18_745, 'status' => 'Success',
                    'date' => '29 Jul 2026', 'time' => '07:38:03',
                    'dividend_per_share' => 1.63, 'dividend_type' => 'Cash', 'id' => '440819045',
                ],
            ]]]],
        ])));

        $this->assertSame(1.63, $rows[0]['dividend_per_share']);
        $this->assertSame('Cash', $rows[0]['dividend_type']);
        $this->assertSame(18_745.0, $rows[0]['net_amount']);
    }

    public function test_a_snapshot_reads_the_balance_share_count_and_the_summary(): void
    {
        $parsed = $this->parser->parseSnapshot($this->parser->decode((string) json_encode([
            'data' => [
                'summary' => [
                    'trading' => ['balance' => 5_989_968.15],
                    'amount' => ['invested' => 139_432_335.33],
                    'profit_loss' => ['net' => -3_269_335.33],
                    'equity' => 142_152_968.15,
                ],
                'results' => [[
                    'symbol' => 'brpt',
                    'stock_on_hand' => 0,
                    'qty' => ['balance' => ['lot' => 115, 'share' => 11_500]],
                    'price' => ['latest' => 1840, 'average' => ['price' => 1663.3609]],
                    'asset' => [
                        'unrealised' => ['market_value' => 21_160_000, 'profit_loss' => 2_031_349.65],
                        'amount_invested' => 19_128_650.35,
                    ],
                ]],
            ],
        ])));

        $this->assertSame(5_989_968.15, $parsed['summary']['cash_balance']);
        $this->assertSame(142_152_968.15, $parsed['summary']['equity']);

        $position = $parsed['positions'][0];
        $this->assertSame('BRPT', $position['symbol']);
        // qty.balance.share, never stock_on_hand.
        $this->assertSame(11_500.0, $position['shares']);
        $this->assertSame(1663.3609, $position['average_price']);
        $this->assertSame(19_128_650.35, $position['amount_invested']);
    }

    public function test_numeric_strings_with_separators_are_understood(): void
    {
        $rows = $this->parser->parseHistory($this->parser->decode((string) json_encode([
            'data' => ['history' => [['history_list' => [
                ['command' => 'BUY', 'symbol' => 'A', 'status' => 'MATCH', 'date' => '01 Jan 2026',
                    'shares' => '6,500', 'price' => '1400', 'amount' => '9,100,000', 'fee' => '13,650', 'id' => '1'],
            ]]]],
        ])));

        $this->assertSame(6500.0, $rows[0]['shares']);
        $this->assertSame(9_100_000.0, $rows[0]['amount']);
        $this->assertSame(13_650.0, $rows[0]['fee']);
    }
}

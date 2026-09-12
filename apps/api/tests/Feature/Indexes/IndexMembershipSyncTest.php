<?php

namespace Tests\Feature\Indexes;

use App\Models\IndexMembership;
use App\Models\MarketIndex;
use App\Services\Indexes\IndexMembershipSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Membership is a dated fact, and a short list is a broken read.
 *
 * The guards are most of what is being tested here. A catalogue page that
 * half-renders produces a well-formed list of the wrong length, and the damage
 * from accepting one is invisible: badges disappear, departures are recorded
 * that never happened, and the next run records the matching joins.
 */
class IndexMembershipSyncTest extends TestCase
{
    use RefreshDatabase;

    private function sync(): IndexMembershipSync
    {
        return app(IndexMembershipSync::class);
    }

    /**
     * @return array<int, string>
     */
    private function members(int $count, string $prefix = 'AA'): array
    {
        $symbols = [];

        for ($index = 0; $index < $count; $index++) {
            $symbols[] = $prefix.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }

        return $symbols;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('market_indexes.indexes.TEST70', [
            'name' => 'Test 70',
            'url' => 'https://example.test/indeks/test70',
            'expected_size' => 70,
            'min_members' => 40,
        ]);
    }

    public function test_first_sync_records_every_member_as_joined_today(): void
    {
        $result = $this->sync()->apply('TEST70', $this->members(45), 'manual', Carbon::parse('2026-09-12'));

        $this->assertTrue($result->accepted);
        $this->assertSame(45, $result->memberCount);
        $this->assertCount(45, $result->joined);
        $this->assertSame([], $result->removed);

        $this->assertDatabaseHas('index_memberships', [
            'index_code' => 'TEST70',
            'symbol' => 'AA00',
            'joined_on' => '2026-09-12',
            'removed_on' => null,
        ]);

        $index = MarketIndex::where('code', 'TEST70')->first();

        $this->assertNotNull($index);
        $this->assertSame(45, $index->member_count);
        $this->assertSame('manual', $index->last_source);
    }

    public function test_a_later_sync_dates_the_arrival_and_the_departure(): void
    {
        $first = $this->members(45);
        $this->sync()->apply('TEST70', $first, 'browser', Carbon::parse('2026-09-01'));

        // One out, one in: the shape of a real index review.
        $second = $first;
        array_shift($second);
        $second[] = 'ZZZZ';

        $result = $this->sync()->apply('TEST70', $second, 'browser', Carbon::parse('2026-09-12'));

        $this->assertTrue($result->accepted);
        $this->assertSame(['ZZZZ'], $result->joined);
        $this->assertSame(['AA00'], $result->removed);
        $this->assertSame(44, $result->unchanged);

        $this->assertDatabaseHas('index_memberships', [
            'index_code' => 'TEST70',
            'symbol' => 'AA00',
            'removed_on' => '2026-09-12',
        ]);

        $this->assertDatabaseHas('index_memberships', [
            'index_code' => 'TEST70',
            'symbol' => 'ZZZZ',
            'joined_on' => '2026-09-12',
            'removed_on' => null,
        ]);
    }

    public function test_a_returning_symbol_starts_a_new_spell(): void
    {
        $full = $this->members(45);
        $this->sync()->apply('TEST70', $full, 'browser', Carbon::parse('2026-01-05'));

        $without = $full;
        array_shift($without);
        $this->sync()->apply('TEST70', $without, 'browser', Carbon::parse('2026-05-05'));

        $result = $this->sync()->apply('TEST70', $full, 'browser', Carbon::parse('2026-09-12'));

        $this->assertSame(['AA00'], $result->joined);

        $membership = IndexMembership::where('index_code', 'TEST70')->where('symbol', 'AA00')->first();

        $this->assertNotNull($membership);
        $this->assertNull($membership->removed_on);
        $this->assertSame('2026-09-12', $membership->joined_on);

        // One row, not two: the spell is rewritten rather than duplicated.
        $this->assertSame(1, IndexMembership::where('index_code', 'TEST70')->where('symbol', 'AA00')->count());
    }

    public function test_an_empty_list_is_refused_even_with_force(): void
    {
        $this->sync()->apply('TEST70', $this->members(45), 'browser', Carbon::parse('2026-09-01'));

        $result = $this->sync()->apply('TEST70', [], 'browser', Carbon::parse('2026-09-12'), true);

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('no usable symbols', (string) $result->refusedReason);
        $this->assertCount(45, $this->sync()->currentSymbols('TEST70'));
    }

    public function test_a_partial_page_is_refused_rather_than_written(): void
    {
        $this->sync()->apply('TEST70', $this->members(45), 'browser', Carbon::parse('2026-09-01'));

        $result = $this->sync()->apply('TEST70', $this->members(12), 'browser', Carbon::parse('2026-09-12'));

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('partial page', (string) $result->refusedReason);
        $this->assertCount(45, $this->sync()->currentSymbols('TEST70'));
    }

    public function test_a_large_drop_is_refused_unless_forced(): void
    {
        $this->sync()->apply('TEST70', $this->members(60), 'browser', Carbon::parse('2026-09-01'));

        // 44 members clears the floor of 40, but drops 16 of 60 -- more than
        // the quarter a review would plausibly replace.
        $shrunk = $this->members(44);

        $refused = $this->sync()->apply('TEST70', $shrunk, 'browser', Carbon::parse('2026-09-12'));

        $this->assertFalse($refused->accepted);
        $this->assertStringContainsString('drop 16 of 60', (string) $refused->refusedReason);
        $this->assertCount(60, $this->sync()->currentSymbols('TEST70'));

        $forced = $this->sync()->apply('TEST70', $shrunk, 'browser', Carbon::parse('2026-09-12'), true);

        $this->assertTrue($forced->accepted);
        $this->assertCount(44, $this->sync()->currentSymbols('TEST70'));
    }

    public function test_page_furniture_is_rejected_rather_than_stored_as_a_member(): void
    {
        $symbols = array_merge($this->members(44), ['more', 'IDX30', 'aaaa', '']);

        $result = $this->sync()->apply('TEST70', $symbols, 'manual', Carbon::parse('2026-09-12'));

        $this->assertTrue($result->accepted);
        $this->assertContains('AAAA', $result->joined, 'a lowercase ticker is a ticker');
        $this->assertContains('MORE', $result->rejected);
        $this->assertContains('IDX30', $result->rejected);
        $this->assertSame(45, $result->memberCount);
    }

    public function test_membership_is_reported_per_symbol_in_one_query(): void
    {
        $this->sync()->apply('TEST70', $this->members(45), 'browser', Carbon::parse('2026-09-12'));

        $map = $this->sync()->currentBySymbol(['AA00', 'NOPE']);

        $this->assertSame(['TEST70'], $map['AA00'] ?? []);
        $this->assertArrayNotHasKey('NOPE', $map);
    }
}

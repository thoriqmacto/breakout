<?php

namespace Tests\Feature\Indexes;

use App\Jobs\BackfillAssetHistoryJob;
use App\Models\Asset;
use App\Models\Metric;
use App\Models\User;
use App\Services\Indexes\IndexMembershipSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The index panel, and the one endpoint that creates assets.
 *
 * `track` is the interesting one. It creates rows in `assets` from a list the
 * browser sent, which is exactly the shape of request that must not be trusted
 * -- so it is checked against the membership this server stored rather than
 * against the payload, and a symbol that is not a current member is refused
 * however plausible it looks.
 */
class MarketIndexApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function members(int $count): array
    {
        $symbols = [];

        for ($index = 0; $index < $count; $index++) {
            $symbols[] = 'AA'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }

        return $symbols;
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('market_indexes.default', 'TEST70');
        config()->set('market_indexes.indexes', [
            'TEST70' => [
                'name' => 'Test 70',
                'description' => 'A test index.',
                'url' => 'https://example.test/indeks/test70',
                'expected_size' => 70,
                'min_members' => 40,
            ],
        ]);
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/indexes')->assertUnauthorized();
        $this->getJson('/api/v1/indexes/TEST70')->assertUnauthorized();
        $this->postJson('/api/v1/indexes/TEST70/track', ['symbols' => ['AA00']])->assertUnauthorized();
    }

    public function test_an_unknown_index_is_a_404_rather_than_an_empty_list(): void
    {
        $this->signIn();

        $this->getJson('/api/v1/indexes/NOPE')->assertNotFound();
        $this->postJson('/api/v1/indexes/NOPE/track', ['symbols' => ['AA00']])->assertNotFound();
    }

    public function test_members_report_whether_this_installation_tracks_them(): void
    {
        $this->signIn();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::parse('2026-09-12'));

        Asset::create(['symbol' => 'AA00', 'name' => 'Alpha Tbk', 'sync_price' => true, 'sync_broker_summary' => false]);

        $response = $this->getJson('/api/v1/indexes/TEST70')->assertOk();

        $payload = $response->json('data');

        $this->assertSame(45, $payload['index']['member_count']);
        $this->assertSame(1, $payload['index']['tracked_count']);
        $this->assertSame('2026-09-12', $payload['index']['last_effective_on']);

        $tracked = collect($payload['members'])->firstWhere('symbol', 'AA00');
        $untracked = collect($payload['members'])->firstWhere('symbol', 'AA01');

        $this->assertTrue($tracked['tracked']);
        $this->assertSame('Alpha Tbk', $tracked['name']);
        $this->assertFalse($tracked['sync_broker_summary']);

        $this->assertFalse($untracked['tracked']);
        // Never zero for a symbol nobody has tried to collect.
        $this->assertNull($untracked['bars']);
    }

    public function test_a_departed_member_is_listed_apart_from_the_current_ones(): void
    {
        $this->signIn();

        $sync = app(IndexMembershipSync::class);
        $full = $this->members(45);
        $sync->apply('TEST70', $full, 'browser', Carbon::parse('2026-09-01'));

        $without = $full;
        array_shift($without);
        $sync->apply('TEST70', $without, 'browser', Carbon::now());

        $payload = $this->getJson('/api/v1/indexes/TEST70')->assertOk()->json('data');

        $this->assertSame(44, $payload['index']['member_count']);
        $this->assertNotContains('AA00', array_column($payload['members'], 'symbol'));
        $this->assertSame(['AA00'], array_column($payload['departed'], 'symbol'));
    }

    public function test_a_pasted_list_updates_the_membership(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/indexes/TEST70/members', [
            'symbols' => $this->members(45),
        ])->assertOk();

        $this->assertTrue($response->json('data.accepted'));
        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));
    }

    public function test_a_pasted_list_that_trips_a_guard_is_refused_with_the_reason(): void
    {
        $this->signIn();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        $response = $this->postJson('/api/v1/indexes/TEST70/members', [
            'symbols' => ['AA00', 'AA01'],
        ])->assertStatus(422);

        $this->assertStringContainsString('partial page', (string) $response->json('errors.refused_reason'));
        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));

        // The same list goes through when a person who can see the real page
        // says it is right.
        $this->postJson('/api/v1/indexes/TEST70/members', [
            'symbols' => ['AA00', 'AA01'],
            'force' => true,
        ])->assertOk();

        $this->assertCount(2, app(IndexMembershipSync::class)->currentSymbols('TEST70'));
    }

    public function test_tracking_creates_assets_that_the_daily_collectors_will_pick_up(): void
    {
        $this->signIn();
        Queue::fake();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        $response = $this->postJson('/api/v1/indexes/TEST70/track', [
            'symbols' => ['AA00', 'AA01'],
        ])->assertOk();

        $this->assertSame(['AA00', 'AA01'], $response->json('data.created'));

        foreach (['AA00', 'AA01'] as $symbol) {
            $asset = Asset::where('symbol', $symbol)->first();

            $this->assertNotNull($asset);
            $this->assertTrue((bool) $asset->sync_price);
            $this->assertTrue((bool) $asset->sync_broker_summary);
        }

        $this->assertStringContainsString('backfill', (string) $response->json('message'));
    }

    public function test_tracking_queues_a_history_backfill_for_each_new_symbol(): void
    {
        $this->signIn();
        Queue::fake();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        // One already tracked, so the request covers both branches at once.
        Asset::create(['symbol' => 'AA01', 'name' => 'Beta', 'sync_price' => false, 'sync_broker_summary' => false]);

        $this->postJson('/api/v1/indexes/TEST70/track', [
            'symbols' => ['AA00', 'AA01', 'GOTO'],
        ])->assertOk();

        // The daily job asks for one session, so without this a new symbol
        // gains one bar an evening and takes a year to become usable.
        Queue::assertPushed(BackfillAssetHistoryJob::class, 1);
        Queue::assertPushed(
            BackfillAssetHistoryJob::class,
            static fn (BackfillAssetHistoryJob $job): bool => $job->symbol === 'AA00',
        );

        // An asset that already existed is not re-scraped from its IPO date,
        // and a symbol that was refused was never created to scrape.
        Queue::assertNotPushed(
            BackfillAssetHistoryJob::class,
            static fn (BackfillAssetHistoryJob $job): bool => in_array($job->symbol, ['AA01', 'GOTO'], true),
        );
    }

    public function test_tracking_refuses_a_symbol_that_is_not_a_member(): void
    {
        $this->signIn();
        Queue::fake();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        $response = $this->postJson('/api/v1/indexes/TEST70/track', [
            'symbols' => ['AA00', 'GOTO'],
        ])->assertOk();

        $this->assertSame(['AA00'], $response->json('data.created'));
        $this->assertSame(['GOTO'], $response->json('data.refused'));
        $this->assertDatabaseMissing('assets', ['symbol' => 'GOTO']);
    }

    public function test_tracking_switches_collection_back_on_for_an_existing_asset(): void
    {
        $this->signIn();
        Queue::fake();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        Asset::create(['symbol' => 'AA00', 'name' => 'Alpha', 'sync_price' => false, 'sync_broker_summary' => false]);

        $response = $this->postJson('/api/v1/indexes/TEST70/track', ['symbols' => ['AA00']])->assertOk();

        $this->assertSame(['AA00'], $response->json('data.already_tracked'));

        $asset = Asset::where('symbol', 'AA00')->first();

        $this->assertTrue((bool) $asset->sync_price);
        $this->assertTrue((bool) $asset->sync_broker_summary);
    }

    public function test_the_metrics_table_carries_the_badge(): void
    {
        $this->signIn();

        app(IndexMembershipSync::class)->apply('TEST70', $this->members(45), 'browser', Carbon::now());

        $asset = Asset::create(['symbol' => 'AA00', 'name' => 'Alpha Tbk']);
        Asset::create(['symbol' => 'ZZZZ', 'name' => 'Outside Tbk']);

        Metric::create(['asset_id' => $asset->id, 'symbol' => 'AA00', 'name' => 'Alpha Tbk']);
        Metric::create([
            'asset_id' => Asset::where('symbol', 'ZZZZ')->value('id'),
            'symbol' => 'ZZZZ',
            'name' => 'Outside Tbk',
        ]);

        $metrics = $this->getJson('/api/v1/assets/metrics')->assertOk()->json('data.metrics');

        $member = collect($metrics)->firstWhere('symbol', 'AA00');
        $outsider = collect($metrics)->firstWhere('symbol', 'ZZZZ');

        $this->assertSame(['TEST70'], $member['indexes']);
        $this->assertSame([], $outsider['indexes']);
    }
}

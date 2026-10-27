<?php

namespace Tests\Feature;

use App\Jobs\BackfillAssetHistoryJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An asset added from the Assets page gets the same history as one added
 * from an index panel.
 *
 * It used not to. The index panel queued a backfill from the IPO date; this
 * form only created the row, so the daily collector gave the new symbol one
 * bar an evening -- and since the collector's recovery only looks back to a
 * ticker's earliest bar, the years before it were never anyone's to fetch.
 */
class AssetCreateBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        Queue::fake();
    }

    public function test_a_new_asset_queues_its_history_from_the_ipo_date(): void
    {
        $this->postJson('/api/v1/assets', ['symbol' => 'CUAN', 'name' => 'Petrindo Jaya Kreasi'])
            ->assertCreated()
            ->assertJsonPath('message', 'Asset created. Its price history from the IPO date is being fetched in the background.');

        Queue::assertPushed(
            BackfillAssetHistoryJob::class,
            static fn (BackfillAssetHistoryJob $job): bool => $job->symbol === 'CUAN',
        );
    }

    /**
     * sync_price off is the operator saying "do not collect prices for this",
     * and a full history is the largest price collection there is.
     */
    public function test_an_asset_created_with_price_sync_off_is_left_alone(): void
    {
        $this->postJson('/api/v1/assets', ['symbol' => 'MUTE', 'name' => 'Muted', 'sync_price' => false])
            ->assertCreated();

        Queue::assertNotPushed(BackfillAssetHistoryJob::class);
    }
}

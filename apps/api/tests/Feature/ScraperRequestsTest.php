<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScraperRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_requires_authentication(): void
    {
        $user = User::factory()->create();

        $record = $user->scraperRequests()->create($this->samplePayload());

        $this->deleteJson("/api/v1/scraper-requests/{$record->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('scraper_requests', ['id' => $record->id]);
    }

    public function test_user_can_delete_own_saved_command(): void
    {
        $user = User::factory()->create();

        $record = $user->scraperRequests()->create($this->samplePayload());

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/scraper-requests/{$record->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Saved command deleted successfully.');

        $this->assertDatabaseMissing('scraper_requests', ['id' => $record->id]);
    }

    public function test_user_cannot_delete_other_users_command(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $othersRecord = $otherUser->scraperRequests()->create($this->samplePayload());

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/scraper-requests/{$othersRecord->id}")
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Saved command not found.');

        $this->assertDatabaseHas('scraper_requests', ['id' => $othersRecord->id]);
    }

    /**
     * Build a payload for creating a scraper request record.
     */
    private function samplePayload(): array
    {
        return [
            'base_url' => 'https://example.com',
            'endpoint' => '/v1/test',
            'query' => 'foo=bar',
            'bearer_token' => 'secret-token',
            'request_url' => 'https://example.com/v1/test?foo=bar',
            'curl_command' => 'curl https://example.com/v1/test?foo=bar',
            'response_status' => '200 OK',
            'response_body' => '{"ok":true}',
        ];
    }
}

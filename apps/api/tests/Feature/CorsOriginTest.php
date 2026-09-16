<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What the API does with an origin it has not been told about.
 *
 * The answer, from the browser's side, is four words: the preflight response
 * is missing the allow-origin header. That is all a browser can say, and it is
 * the same four words whether the allowlist is missing one entry, was never
 * configured, or the dashboard is being reached by a hostname nobody thought
 * to add -- every Vercel preview deployment has its own, and an apex and a www
 * spelling are two different origins.
 *
 * The server knows which origin was offered. Saying so is the difference
 * between a configuration error anyone can fix in a minute and one that takes
 * an afternoon.
 */
class CorsOriginTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_an_allowed_origin_is_answered_with_its_own_origin(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example']]);

        $this->preflight('https://dashboard.example')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://dashboard.example');
    }

    /**
     * A trailing slash is not a different origin, whichever side carries it.
     */
    public function test_a_trailing_slash_does_not_make_a_different_origin(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example/']]);

        $this->preflight('https://dashboard.example')
            ->assertHeader('Access-Control-Allow-Origin', 'https://dashboard.example');
    }

    public function test_an_unlisted_origin_gets_no_header_and_is_named_in_the_log(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example']]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'CORS')
                    // The origin that was turned down, which is the only fact
                    // that makes this fixable, and which the browser cannot
                    // report back to the server.
                    && $context['origin'] === 'https://breakout-git-a-preview.vercel.app'
                    // And how many were configured, which separates a list
                    // missing an entry from one that was never set.
                    && $context['allowed_origins'] === 1;
            });

        $response = $this->preflight('https://breakout-git-a-preview.vercel.app');

        $response->assertNoContent();

        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'an origin that is not on the allowlist must not be told that it is',
        );
    }

    /**
     * A request with no Origin is not a rejection and must not be logged as
     * one: every curl, health check and server-to-server call sends none.
     */
    public function test_a_request_without_an_origin_logs_nothing(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example']]);

        Log::shouldReceive('warning')->never();

        $this->getJson('/api/ping')->assertOk();
    }
}

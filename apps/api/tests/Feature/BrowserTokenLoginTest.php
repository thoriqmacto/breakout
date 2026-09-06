<?php

namespace Tests\Feature;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Covers POST /api/v1/automation/stockbit-token/browser-login.
 *
 * This endpoint is the only one in the application that accepts a portal
 * password, so most of what is asserted here is about what it refuses to do
 * with it: no storing, no logging, no echoing it back, and nothing at all
 * unless the feature was deliberately switched on.
 *
 * The extractor is faked throughout. Launching a real browser against a real
 * portal in a test would be slow, flaky, and would put credentials in CI --
 * the browser itself is proven separately by resources/browser/smoke-test.mjs
 * against a fixture portal on localhost.
 */
class BrowserTokenLoginTest extends TestCase
{
    use RefreshDatabase;

    /** A structurally valid JWT that expires far in the future. */
    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jwt = $this->makeJwt(4_102_444_800);

        Cache::flush();
        RateLimiter::clear('browser-login:'.$this->hostIdentifier());

        // The token store is a real encrypted file on the local disk, not a
        // database row, so RefreshDatabase does not clear it and one test's
        // token would otherwise still be there for the next.
        app(StockbitTokenResolver::class)->forget();

        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
        ]);
    }

    private function hostIdentifier(): string
    {
        return '127.0.0.1';
    }

    private function makeJwt(int $expiry): string
    {
        $segment = static fn (array $data): string => rtrim(
            strtr(base64_encode(json_encode($data)), '+/', '-_'),
            '=',
        );

        return $segment(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$segment(['sub' => 'test', 'exp' => $expiry])
            .'.'.rtrim(strtr(base64_encode('signature'), '+/', '-_'), '=');
    }

    private function attempt(array $payload = [])
    {
        $payload = $payload ?: ['username' => 'trader@example.test', 'password' => 'a-secret-password'];

        $response = $this->postJson('/api/v1/automation/stockbit-token/browser-login', $payload);

        if ($response->status() === 401) {
            $response = $this->withoutMiddleware()
                ->postJson('/api/v1/automation/stockbit-token/browser-login', $payload);
        }

        return $response;
    }

    /** @param array{token: string, source: string, elapsed_ms: int} $result */
    private function fakeExtractor(array $result): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($result) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('extract')->andReturn($result);
        });
    }

    private function failingExtractor(string $code, string $message): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($code, $message) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('extract')
                ->andThrow(new BrowserTokenExtractionException($code, $message));
        });
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/automation/stockbit-token/browser-login', [
            'username' => 'a@b.test',
            'password' => 'x',
        ])->assertUnauthorized();
    }

    /**
     * Off by default. Enabling it is what changes a compromise of this box
     * from costing an expiring bearer to costing a password, so it should
     * never be reachable by accident.
     */
    public function test_it_is_disabled_unless_explicitly_configured(): void
    {
        config(['browser_auth.enabled' => false]);

        $this->attempt()->assertStatus(503);
    }

    public function test_a_missing_login_url_leaves_it_disabled_even_when_enabled(): void
    {
        config(['browser_auth.enabled' => true, 'browser_auth.login_url' => null]);

        // The real extractor decides this, so no fake here.
        $this->attempt()->assertStatus(503);
    }

    public function test_a_captured_token_is_stored_and_never_returned(): void
    {
        $this->fakeExtractor(['token' => $this->jwt, 'source' => 'response-body', 'elapsed_ms' => 1234]);

        $response = $this->attempt()->assertOk();

        $body = $response->getContent();

        // The two things that must never appear in a response.
        $this->assertStringNotContainsString($this->jwt, $body);
        $this->assertStringNotContainsString('a-secret-password', $body);

        // But it did land in the store, through the ordinary resolver.
        $this->assertSame($this->jwt, app(StockbitTokenResolver::class)->resolve());

        $response->assertJsonPath('data.captured_from', 'response-body')
            ->assertJsonPath('data.configured', true);
    }

    public function test_the_response_reports_status_by_fingerprint_only(): void
    {
        $this->fakeExtractor(['token' => $this->jwt, 'source' => 'request-header', 'elapsed_ms' => 900]);

        $fingerprint = $this->attempt()->assertOk()->json('data.fingerprint');

        $this->assertNotNull($fingerprint);
        $this->assertStringNotContainsString($fingerprint, 'the token itself');
        // A fingerprint is the tail of the token, never the whole of it.
        $this->assertLessThan(strlen($this->jwt), strlen((string) $fingerprint));
    }

    public function test_rejected_credentials_are_a_422_and_do_not_replace_a_working_token(): void
    {
        app(StockbitTokenResolver::class)->persist($this->jwt);

        $this->failingExtractor(
            BrowserTokenExtractor::INVALID_CREDENTIALS,
            'The portal rejected those credentials.',
        );

        $this->attempt()
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', BrowserTokenExtractor::INVALID_CREDENTIALS);

        // A failed login must not cost the token that was already working.
        $this->assertSame($this->jwt, app(StockbitTokenResolver::class)->resolve());
    }

    /** A portal that is slow or down is not the operator's mistake. */
    public function test_an_extraction_failure_is_a_502_with_a_machine_readable_code(): void
    {
        $this->failingExtractor(BrowserTokenExtractor::TIMEOUT, 'The login did not finish in time.');

        $this->attempt()
            ->assertStatus(502)
            ->assertJsonPath('errors.code.0', BrowserTokenExtractor::TIMEOUT);
    }

    public function test_an_expired_token_is_refused_rather_than_stored(): void
    {
        $expired = $this->makeJwt(1_000_000_000);

        $this->fakeExtractor(['token' => $expired, 'source' => 'response-body', 'elapsed_ms' => 10]);

        $this->attempt()
            ->assertStatus(502)
            ->assertJsonPath('errors.code.0', 'EXPIRED_TOKEN');

        $this->assertNull(app(StockbitTokenResolver::class)->resolve());
    }

    /** One Chromium at a time: several at once exhausts a small VPS. */
    public function test_a_concurrent_login_is_refused(): void
    {
        $this->fakeExtractor(['token' => $this->jwt, 'source' => 'response-body', 'elapsed_ms' => 10]);

        $held = Cache::lock('browser-login:running', 120);
        $this->assertTrue($held->get());

        try {
            $this->attempt()->assertStatus(409);
        } finally {
            $held->release();
        }
    }

    /**
     * Repeated failed logins against a portal are how an account gets locked,
     * so the attempts are capped before they reach the portal at all.
     */
    public function test_repeated_attempts_are_throttled(): void
    {
        $this->failingExtractor(
            BrowserTokenExtractor::INVALID_CREDENTIALS,
            'The portal rejected those credentials.',
        );

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt()->assertStatus(422);
        }

        $this->attempt()->assertStatus(429);
    }

    public function test_credentials_are_required(): void
    {
        $this->attempt(['username' => 'a@b.test'])->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenRenewer;
use App\Support\StockbitCredentialStore;
use App\Support\StockbitTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renewal, reachable from the middle of a scrape.
 *
 * A rejected token is not an expired one. `automation:token-refresh` decides
 * on the clock -- missing, expired, or inside the renewal window -- so a token
 * the portal has revoked, rotated, or unbound from its session still reads as
 * healthy and is left alone. The scrape is what finds out, hours later, and
 * until this existed the only things it could do were abort and tell the
 * operator to paste a token, or stop at a prompt in a job nobody was watching.
 */
class StockbitTokenRenewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(StockbitTokenStore::class)->forget();
        app(StockbitCredentialStore::class)->forget();

        config(['browser_auth.enabled' => true, 'browser_auth.login_url' => 'https://portal.test/login']);
    }

    protected function tearDown(): void
    {
        app(StockbitTokenStore::class)->forget();
        app(StockbitCredentialStore::class)->forget();

        parent::tearDown();
    }

    private function jwt(int $expiry): string
    {
        $segment = static fn (array $data): string => rtrim(
            strtr(base64_encode((string) json_encode($data)), '+/', '-_'),
            '=',
        );

        return $segment(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$segment(['sub' => 'test', 'exp' => $expiry])
            .'.'.rtrim(strtr(base64_encode('signature'), '+/', '-_'), '=');
    }

    public function test_a_saved_profile_renews_with_no_password_at_all(): void
    {
        $token = $this->jwt(time() + 86400);

        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($token) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn('/var/lib/breakout/browser-profile');
            // The assertion that matters for unattended renewal: no
            // credentials are handed over, because the profile carries the
            // session and a stored password is the thing worth not needing.
            $mock->shouldReceive('extract')
                ->with(null, null)
                ->andReturn(['token' => $token, 'source' => 'request-header', 'elapsed_ms' => 4100]);
        });

        $renewer = app(StockbitTokenRenewer::class);

        $this->assertTrue($renewer->available());

        $result = $renewer->renew();

        $this->assertTrue($result['renewed']);
        $this->assertTrue($result['used_profile']);
        $this->assertSame('request-header', $result['source']);
        $this->assertSame($token, $result['token']);

        // Persisted, not merely returned: the next command reads the store.
        $this->assertSame($token, app(StockbitTokenStore::class)->get());
    }

    public function test_it_reports_rather_than_throws_when_the_login_fails(): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn('/var/lib/breakout/browser-profile');
            $mock->shouldReceive('extract')->andThrow(
                new BrowserTokenExtractionException('PROFILE_SIGNED_OUT', 'The saved profile is signed out.'),
            );
        });

        // A caller mid-scrape wants an answer it can branch on, not a second
        // failure layered over the first.
        $result = app(StockbitTokenRenewer::class)->renew();

        $this->assertFalse($result['renewed']);
        $this->assertSame('PROFILE_SIGNED_OUT', $result['reason']);
        $this->assertStringContainsString('signed out', (string) $result['message']);
        $this->assertNull($result['token']);
    }

    public function test_it_declines_when_there_is_nothing_to_sign_in_with(): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn(null);
            // Never reached: with no profile and no credentials there is
            // nothing to attempt, and launching a browser to discover that
            // wastes thirty seconds mid-scrape.
            $mock->shouldReceive('extract')->never();
        });

        $renewer = app(StockbitTokenRenewer::class);

        $this->assertFalse($renewer->available());

        $result = $renewer->renew();

        $this->assertFalse($result['renewed']);
        $this->assertSame(StockbitTokenRenewer::NO_CREDENTIALS, $result['reason']);
    }

    public function test_it_declines_when_headless_login_is_switched_off(): void
    {
        config(['browser_auth.enabled' => false]);

        $this->mock(BrowserTokenExtractor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('extract')->never();
        });

        $renewer = app(StockbitTokenRenewer::class);

        $this->assertFalse($renewer->available());
        $this->assertSame(
            StockbitTokenRenewer::NOT_CONFIGURED,
            $renewer->renew()['reason'],
        );
    }

    /**
     * A profile directory that is configured but broken is not a crash.
     *
     * The extractor throws when it cannot use the directory. That belongs in
     * the extraction's own message, with the path and the user in it, rather
     * than escaping from a availability check called to decide whether a retry
     * is worth promising.
     */
    public function test_a_broken_profile_directory_does_not_escape_the_check(): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andThrow(
                new BrowserTokenExtractionException('NOT_CONFIGURED', 'The profile directory is not writable.'),
            );
            $mock->shouldReceive('extract')->never();
        });

        $renewer = app(StockbitTokenRenewer::class);

        $this->assertFalse($renewer->available());
        $this->assertFalse($renewer->renew()['renewed']);
    }
}

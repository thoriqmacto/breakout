<?php

namespace Tests\Feature;

use App\Models\AutomationAlert;
use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\Stockbit\StockbitTokenVerifier;
use App\Support\StockbitCredentialStore;
use App\Support\StockbitTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Unattended renewal: it must renew when it should, and be loud when it cannot.
 *
 * The failure mode worth guarding is silence. A renewal that quietly does not
 * happen looks exactly like one that did, until the 16:00 scrape fails on an
 * expired token hours later -- so every path that does not end in a fresh
 * token has to end in the dashboard reminder instead.
 */
class StockbitTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Both stores are real files rather than database rows, so nothing
        // RefreshDatabase does clears them between tests.
        app(StockbitTokenStore::class)->forget();
        app(StockbitCredentialStore::class)->forget();

        config(['browser_auth.enabled' => true, 'browser_auth.login_url' => 'https://portal.test/login']);

        // Verification has its own tests; these are about renewal. Without a
        // stub the verifier makes a live call, and the suite then reports
        // whether the machine running it happens to reach the portal -- it
        // stored the token on a sandbox with no route out and refused it on a
        // CI runner with one, from identical code.
        $this->mock(StockbitTokenVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn([
                'status' => StockbitTokenVerifier::OK,
                'message' => null,
            ])->byDefault();
        });
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

    private function extractorReturning(string $token): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($token) {
            $mock->shouldReceive('enabled')->andReturn(true);
            // No saved profile: these cases are about the stored-credential path.
            $mock->shouldReceive('profileDir')->andReturn(null);
            $mock->shouldReceive('extract')
                ->andReturn(['token' => $token, 'source' => 'storage:sb_session', 'elapsed_ms' => 4200]);
        });
    }

    private function extractorFailing(string $code, string $message): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($code, $message) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn(null);
            $mock->shouldReceive('extract')
                ->andThrow(new BrowserTokenExtractionException($code, $message));
        });
    }

    private function openAlerts(): int
    {
        return AutomationAlert::query()
            ->where('type', AutomationAlert::TYPE_STOCKBIT_TOKEN)
            ->whereNull('resolved_at')
            ->count();
    }

    public function test_a_token_inside_the_renewal_window_is_replaced(): void
    {
        app(StockbitTokenStore::class)->put($this->jwt(time() + 1800));

        $fresh = $this->jwt(time() + 86_400);
        $this->extractorReturning($fresh);
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');

        $this->assertSame(0, Artisan::call('automation:token-refresh', ['--minutes' => '120']));
        $this->assertSame($fresh, app(StockbitTokenResolver::class)->resolve());
    }

    /**
     * A browser launch costs hundreds of megabytes and tens of seconds, and
     * every needless login is another chance for the portal to notice a robot.
     */
    public function test_a_healthy_token_is_left_alone(): void
    {
        $existing = $this->jwt(time() + 86_400);
        app(StockbitTokenStore::class)->put($existing);
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');

        $this->mock(BrowserTokenExtractor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn(null);
            $mock->shouldNotReceive('extract');
        });

        $this->assertSame(0, Artisan::call('automation:token-refresh', ['--minutes' => '120']));
        $this->assertSame($existing, app(StockbitTokenResolver::class)->resolve());
    }

    public function test_force_renews_a_healthy_token(): void
    {
        app(StockbitTokenStore::class)->put($this->jwt(time() + 86_400));
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');

        $fresh = $this->jwt(time() + 172_800);
        $this->extractorReturning($fresh);

        $this->assertSame(0, Artisan::call('automation:token-refresh', ['--force' => true]));
        $this->assertSame($fresh, app(StockbitTokenResolver::class)->resolve());
    }

    public function test_without_credentials_or_a_profile_it_raises_the_reminder(): void
    {
        config(['browser_auth.profile_dir' => null]);

        $this->assertSame(1, Artisan::call('automation:token-refresh'));

        $this->assertSame(1, $this->openAlerts());
        $this->assertStringContainsString('stockbit:credentials', Artisan::output());
    }

    /**
     * A saved profile removes the reason to store a password at all.
     *
     * Renewal through a session the portal already trusts is strictly better
     * than renewal through stored credentials: a stolen disk gives up a
     * session that can be revoked rather than a password that cannot. So an
     * available profile must be enough on its own.
     */
    public function test_a_saved_profile_renews_without_any_stored_password(): void
    {
        $fresh = $this->jwt(time() + 86_400);

        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($fresh) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileDir')->andReturn('/tmp/browser-profile');
            // Called with no credentials whatsoever, which is the point.
            $mock->shouldReceive('extract')
                ->with(null, null)
                ->andReturn(['token' => $fresh, 'source' => 'cookie:credentialStorage', 'elapsed_ms' => 1200]);
        });

        $this->assertSame(0, Artisan::call('automation:token-refresh'));
        $this->assertSame($fresh, app(StockbitTokenResolver::class)->resolve());
        $this->assertSame(0, $this->openAlerts());
    }

    public function test_a_failed_login_raises_the_reminder_and_keeps_the_old_token(): void
    {
        $existing = $this->jwt(time() + 600);
        app(StockbitTokenStore::class)->put($existing);
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');

        $this->extractorFailing(
            BrowserTokenExtractor::INVALID_CREDENTIALS,
            'The portal rejected those credentials.',
        );

        $this->assertSame(1, Artisan::call('automation:token-refresh'));
        $this->assertSame(1, $this->openAlerts());

        // A failed renewal must not cost the token that still has minutes on it.
        $this->assertSame($existing, app(StockbitTokenResolver::class)->resolve());
    }

    public function test_a_successful_renewal_clears_a_standing_reminder(): void
    {
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');
        $this->extractorFailing(BrowserTokenExtractor::TIMEOUT, 'The login did not finish in time.');

        Artisan::call('automation:token-refresh');
        $this->assertSame(1, $this->openAlerts());

        $this->extractorReturning($this->jwt(time() + 86_400));

        $this->assertSame(0, Artisan::call('automation:token-refresh'));
        $this->assertSame(0, $this->openAlerts());
    }

    /**
     * A scheduled task's parameters are stored in the database and shown in
     * the dashboard, so credentials must have no way in through that door.
     */
    public function test_the_scheduled_command_accepts_no_credential_parameters(): void
    {
        $options = config('automation.commands.automation:token-refresh.options');

        $this->assertIsArray($options);
        $this->assertSame(['minutes', 'force'], array_keys($options));
    }

    public function test_the_password_is_never_echoed_by_the_command(): void
    {
        app(StockbitCredentialStore::class)->put('trader@example.test', 'a-secret-password');
        $this->extractorFailing('SELECTOR_NOT_FOUND', 'A field on the login form was not found.');

        Artisan::call('automation:token-refresh');

        $this->assertStringNotContainsString('a-secret-password', Artisan::output());
    }
}

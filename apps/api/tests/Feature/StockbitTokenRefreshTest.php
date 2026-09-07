<?php

namespace Tests\Feature;

use App\Models\AutomationAlert;
use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
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
            $mock->shouldReceive('extract')
                ->andReturn(['token' => $token, 'source' => 'storage:sb_session', 'elapsed_ms' => 4200]);
        });
    }

    private function extractorFailing(string $code, string $message): void
    {
        $this->mock(BrowserTokenExtractor::class, function ($mock) use ($code, $message) {
            $mock->shouldReceive('enabled')->andReturn(true);
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

    public function test_without_credentials_it_raises_the_reminder_rather_than_passing_quietly(): void
    {
        $this->assertSame(1, Artisan::call('automation:token-refresh'));

        $this->assertSame(1, $this->openAlerts());
        $this->assertStringContainsString('stockbit:credentials', Artisan::output());
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

<?php

namespace Tests\Feature;

use App\Services\GoogleDrive\GoogleDriveOAuth;
use App\Support\GoogleDriveTokenStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Connecting Drive from the dashboard rather than the OAuth Playground.
 *
 * The parts worth defending are the ones that are easy to get subtly wrong:
 * the state that stops the callback accepting anybody's authorization code,
 * the environment fallback that keeps an existing installation running, and
 * the rule that no endpoint here ever answers with the token itself.
 */
class GoogleDriveOAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'filesystems.disks.gdrive.clientId' => 'client-id.apps.googleusercontent.com',
            'filesystems.disks.gdrive.clientSecret' => 'client-secret',
            'filesystems.disks.gdrive.refreshToken' => '',
            'google_drive.redirect_uri' => 'https://api.test/api/v1/integrations/google-drive/callback',
            'app.frontend_url' => 'https://dashboard.test',
            'google_drive.return_path' => '/dashboard/backups',
        ]);
    }

    private function store(): GoogleDriveTokenStore
    {
        return app(GoogleDriveTokenStore::class);
    }

    private function oauth(): GoogleDriveOAuth
    {
        return app(GoogleDriveOAuth::class);
    }

    public function test_the_stored_grant_is_encrypted_and_read_back(): void
    {
        $this->store()->put('1//refresh-token', 'ops@example.com');

        $this->assertSame('1//refresh-token', $this->store()->get());
        $this->assertSame('ops@example.com', $this->store()->account());
        $this->assertSame(GoogleDriveTokenStore::SOURCE_STORE, $this->store()->source());

        // The file on disk must not contain the token in the clear.
        $raw = Storage::disk('local')->get('google-drive/token.json');
        $this->assertStringNotContainsString('1//refresh-token', (string) $raw);
    }

    /**
     * The whole point of keeping the environment variable: an installation
     * that has not pressed the button yet must not lose Drive on deploy.
     */
    public function test_it_falls_back_to_the_environment_token(): void
    {
        config(['filesystems.disks.gdrive.refreshToken' => '1//from-env']);

        $this->assertSame('1//from-env', $this->store()->get());
        $this->assertSame(GoogleDriveTokenStore::SOURCE_ENV, $this->store()->source());

        // A grant made through the button takes precedence from then on.
        $this->store()->put('1//from-consent');

        $this->assertSame('1//from-consent', $this->store()->get());
        $this->assertSame(GoogleDriveTokenStore::SOURCE_STORE, $this->store()->source());
    }

    public function test_source_is_none_when_nothing_is_configured(): void
    {
        $this->assertNull($this->store()->get());
        $this->assertFalse($this->store()->has());
        $this->assertSame(GoogleDriveTokenStore::SOURCE_NONE, $this->store()->source());
    }

    public function test_state_is_single_use(): void
    {
        $url = $this->oauth()->authorizationUrl('ops@example.com');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';

        $this->assertNotSame('', $state);
        $this->assertTrue($this->oauth()->consumeState($state));
        $this->assertFalse($this->oauth()->consumeState($state), 'A replayed state must not verify.');
    }

    public function test_the_consent_url_asks_for_offline_access_and_consent(): void
    {
        parse_str((string) parse_url($this->oauth()->authorizationUrl(), PHP_URL_QUERY), $query);

        // Without both of these Google returns no refresh token on a
        // reconnect, which is exactly when the button is being pressed.
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);
        $this->assertStringContainsString('auth/drive', (string) ($query['scope'] ?? ''));
    }

    public function test_the_callback_rejects_an_unknown_state(): void
    {
        $this->get('/api/v1/integrations/google-drive/callback?state=never-issued&code=abc')
            ->assertRedirect('https://dashboard.test/dashboard/backups?drive=invalid_state');

        $this->assertFalse($this->store()->has(), 'A rejected callback must store nothing.');
    }

    public function test_the_callback_reports_a_cancelled_consent(): void
    {
        $this->get('/api/v1/integrations/google-drive/callback?error=access_denied')
            ->assertRedirect('https://dashboard.test/dashboard/backups?drive=denied');
    }

    public function test_the_callback_reports_a_missing_code(): void
    {
        Cache::put('google-drive:oauth:abc', ['started_by' => null], now()->addMinutes(5));

        $this->get('/api/v1/integrations/google-drive/callback?state=abc')
            ->assertRedirect('https://dashboard.test/dashboard/backups?drive=invalid');
    }

    /**
     * The callback is the one unauthenticated route here, by necessity:
     * Google redirects the browser to it with no Authorization header. If it
     * ever starts 401ing, connecting breaks for everyone.
     */
    public function test_the_callback_route_is_reachable_without_authentication(): void
    {
        $this->get('/api/v1/integrations/google-drive/callback')
            ->assertRedirect('https://dashboard.test/dashboard/backups?drive=invalid');
    }

    public function test_the_status_endpoint_never_returns_the_token(): void
    {
        $this->store()->put('1//refresh-token', 'ops@example.com');

        $response = $this->withoutMiddleware()->getJson('/api/v1/integrations/google-drive');

        $response->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.source', 'store')
            ->assertJsonPath('data.account', 'ops@example.com');

        $this->assertStringNotContainsString('1//refresh-token', $response->getContent() ?: '');

        // Four characters, which identifies the grant and reveals nothing.
        $this->assertSame(4, strlen((string) $response->json('data.fingerprint')));
    }

    public function test_redirect_refuses_when_oauth_is_not_configured(): void
    {
        config(['google_drive.redirect_uri' => null]);

        $this->withoutMiddleware()
            ->postJson('/api/v1/integrations/google-drive/redirect')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_redirect_hands_back_a_google_consent_url(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/v1/integrations/google-drive/redirect')
            ->assertOk();

        $url = (string) $response->json('data.authorization_url');

        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        $this->assertStringNotContainsString('client-secret', $url, 'The secret must never be in the URL.');
    }

    public function test_disconnect_forgets_the_stored_grant(): void
    {
        $this->store()->put('1//refresh-token');

        $this->withoutMiddleware()
            ->deleteJson('/api/v1/integrations/google-drive')
            ->assertOk();

        $this->assertFalse($this->store()->has());
    }
}

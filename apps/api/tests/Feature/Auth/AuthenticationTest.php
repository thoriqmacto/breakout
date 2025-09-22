<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_issues_token(): void
    {
        $response = $this->withHeader('Accept', 'application/json')
            ->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');

        $xsrfCookie = $response->getCookie('XSRF-TOKEN');

        $this->assertNotNull($xsrfCookie);
        $this->assertNotSame('', (string) $xsrfCookie?->getValue());
    }

    public function test_user_can_register_and_receive_tokens(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
                'access_token',
                'refresh_token',
                'access_expires_at',
                'refresh_expires_at',
            ]);

        $accessToken = $response->json('access_token');

        $this->getJson('/api/auth/me', [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertOk()
            ->assertJsonPath('user.email', 'test@example.com');
    }

    public function test_login_requires_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'incorrect',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
            ]);
    }

    public function test_refresh_rotates_tokens_and_invalidates_previous_token(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $refreshToken = $login->json('refresh_token');

        $firstRefresh = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertOk();

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(401);

        $this->getJson('/api/auth/me', [
            'Authorization' => 'Bearer '.$firstRefresh->json('access_token'),
        ])->assertOk();
    }

    public function test_revoking_refresh_token_blocks_jwt_access(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $refreshToken = $login->json('refresh_token');
        $accessToken = $login->json('access_token');

        $stored = PersonalAccessToken::findToken($refreshToken);
        $this->assertNotNull($stored);
        $stored?->delete();

        $this->getJson('/api/auth/me', [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertStatus(401);
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $refreshToken = $login->json('refresh_token');
        $accessToken = $login->json('access_token');

        $this->postJson('/api/auth/logout', [
            'refresh_token' => $refreshToken,
        ], [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertOk();

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(401);
    }
}

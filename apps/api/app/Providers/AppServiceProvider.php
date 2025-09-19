<?php

namespace App\Providers;

use App\Exceptions\JwtException;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JwtService::class, function ($app) {
            $config = $app['config']->get('jwt');

            $secret = (string) ($config['secret'] ?? '');

            if ($secret === '') {
                $secret = (string) $app['config']->get('app.key', '');
            }

            return new JwtService(
                secret: $secret,
                algo: (string) ($config['algo'] ?? 'HS256'),
                ttl: (int) ($config['ttl'] ?? 3600),
                leeway: (int) ($config['leeway'] ?? 0),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(JwtService $jwt): void
    {
        Auth::viaRequest('jwt', function (Request $request) use ($jwt) {
            $token = $jwt->parseFromRequest($request);

            if (! $token) {
                return null;
            }

            try {
                $payload = $jwt->decode($token);
            } catch (JwtException) {
                return null;
            }

            if (! isset($payload['rtid'])) {
                return null;
            }

            $refreshToken = PersonalAccessToken::query()->find($payload['rtid']);

            if (! $refreshToken || ! $refreshToken->can('refresh')) {
                return null;
            }

            if ($refreshToken->expires_at && $refreshToken->expires_at->isPast()) {
                return null;
            }

            $user = $refreshToken->tokenable;

            return $user instanceof User ? $user : null;
        });
    }
}

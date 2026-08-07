<?php

namespace App\Http\Controllers;

use App\Exceptions\JwtException;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly JwtService $jwt) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        /** @var User $user */
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        /** @var User $user */
        $refreshToken = $this->createRefreshToken($user, $request);
        $jwt = $this->jwt->issue($user, $refreshToken->accessToken);

        return response()->json(
            $this->formatTokenResponse($user, $jwt, $refreshToken),
            201
        );
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        $refreshToken = $this->createRefreshToken($user, $request);
        $jwt = $this->jwt->issue($user, $refreshToken->accessToken);

        return response()->json($this->formatTokenResponse($user, $jwt, $refreshToken));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $user?->only(['id', 'name', 'email']),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $existing = PersonalAccessToken::findToken($data['refresh_token']);

        if (! $existing || ! $existing->can('refresh')) {
            return response()->json([
                'message' => 'Refresh token is invalid or has been revoked.',
            ], 401);
        }

        if ($existing->expires_at && $existing->expires_at->isPast()) {
            $existing->delete();

            return response()->json([
                'message' => 'Refresh token has expired.',
            ], 401);
        }

        $tokenable = $existing->tokenable;

        if (! $tokenable instanceof User) {
            $existing->delete();

            return response()->json([
                'message' => 'Refresh token is not associated with a valid user.',
            ], 401);
        }

        $existing->delete();

        $refreshToken = $this->createRefreshToken($tokenable, $request);
        $jwt = $this->jwt->issue($tokenable, $refreshToken->accessToken);

        return response()->json($this->formatTokenResponse($tokenable, $jwt, $refreshToken));
    }

    public function logout(Request $request): JsonResponse
    {
        $refreshToken = $request->string('refresh_token')->toString();

        if ($refreshToken !== '') {
            $this->revokeRefreshToken($refreshToken);
        } elseif ($bearer = $request->bearerToken()) {
            try {
                $payload = $this->jwt->decode($bearer);
                if (isset($payload['rtid'])) {
                    PersonalAccessToken::query()->find($payload['rtid'])?->delete();
                }
            } catch (JwtException) {
                // If the JWT is invalid we simply skip targeted revocation.
            }
        }

        return response()->json(['message' => 'Logged out']);
    }

    private function createRefreshToken(User $user, Request $request): NewAccessToken
    {
        $name = $this->tokenName($request);

        $user->tokens()->where('name', $name)->delete();

        $expiresAt = Carbon::now()->addSeconds((int) config('jwt.refresh_ttl'));

        return $user->createToken($name, ['refresh'], $expiresAt);
    }

    private function formatTokenResponse(User $user, array $jwt, NewAccessToken $refreshToken): array
    {
        $refreshExpiresAt = $refreshToken->accessToken->expires_at;

        return [
            'user' => $user->only(['id', 'name', 'email']),
            'access_token' => $jwt['token'],
            'access_expires_at' => $jwt['expires_at']->toIso8601String(),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->ttl(),
            'refresh_token' => $refreshToken->plainTextToken,
            'refresh_expires_at' => $refreshExpiresAt?->toIso8601String(),
        ];
    }

    private function tokenName(Request $request): string
    {
        $fingerprint = $request->input('device_name')
            ?: $request->header('X-Client-Id')
            ?: ($request->userAgent() ?: 'api-client');

        return 'jwt-refresh:'.substr(hash('sha256', $fingerprint.'|'.$request->ip()), 0, 40);
    }

    private function revokeRefreshToken(string $token): void
    {
        $stored = PersonalAccessToken::findToken($token);

        if ($stored) {
            $stored->delete();
        }
    }
}

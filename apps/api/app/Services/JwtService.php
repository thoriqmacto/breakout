<?php

namespace App\Services;

use App\Exceptions\JwtException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use JsonException;
use Laravel\Sanctum\PersonalAccessToken;

class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algo,
        private readonly int $ttl,
        private readonly int $leeway
    ) {
        if ($this->secret === '') {
            throw new JwtException('JWT secret is not configured.');
        }
    }

    /**
     * Issue a signed JWT for the given user and refresh token.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function issue(User $user, PersonalAccessToken $refreshToken, array $customClaims = []): array
    {
        $issuedAt = Carbon::now();
        $expiresAt = $issuedAt->copy()->addSeconds($this->ttl);

        $payload = array_merge($customClaims, [
            'iss' => config('app.url'),
            'sub' => (string) $user->getAuthIdentifier(),
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'rtid' => $refreshToken->getKey(),
        ]);

        return [
            'token' => $this->encode($payload),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Attempt to retrieve a bearer token from the request.
     */
    public function parseFromRequest(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization', '');

        if (is_string($authorization) && str_starts_with(strtolower($authorization), 'bearer ')) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * Decode and validate the supplied JWT, returning the payload on success.
     *
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new JwtException('Token structure is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        try {
            $header = json_decode($this->base64UrlDecode($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JwtException('Token payload could not be decoded.', previous: $exception);
        }

        if (! is_array($header) || ! isset($header['alg'])) {
            throw new JwtException('Token header is malformed.');
        }

        if (strtoupper((string) $header['alg']) !== strtoupper($this->algo)) {
            throw new JwtException('Token uses an unexpected signing algorithm.');
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        $expectedSignature = $this->sign("{$encodedHeader}.{$encodedPayload}");

        if (! hash_equals($expectedSignature, $signature)) {
            throw new JwtException('Token signature is invalid.');
        }

        $now = Carbon::now()->getTimestamp();

        if (isset($payload['nbf']) && $payload['nbf'] > ($now + $this->leeway)) {
            throw new JwtException('Token is not valid yet.');
        }

        if (isset($payload['iat']) && $payload['iat'] > ($now + $this->leeway)) {
            throw new JwtException('Token issue time is in the future.');
        }

        if (! isset($payload['exp']) || $payload['exp'] <= ($now - $this->leeway)) {
            throw new JwtException('Token has expired.');
        }

        return $payload;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Base64 URL encode the provided value.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @throws JwtException
     */
    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new JwtException('Token could not be decoded from base64.');
        }

        return $decoded;
    }

    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => strtoupper($this->algo),
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signature = $this->base64UrlEncode($this->sign("{$header}.{$body}"));

        return implode('.', [$header, $body, $signature]);
    }

    /**
     * @throws JwtException
     */
    private function sign(string $message): string
    {
        return match (strtoupper($this->algo)) {
            'HS256' => hash_hmac('sha256', $message, $this->secret, true),
            'HS384' => hash_hmac('sha384', $message, $this->secret, true),
            'HS512' => hash_hmac('sha512', $message, $this->secret, true),
            default => throw new JwtException('Unsupported JWT signing algorithm.'),
        };
    }
}

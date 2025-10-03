# Breakout API Authentication

The Breakout API exposes two complementary authentication strategies:

1. **Stateless bearer tokens backed by Laravel Sanctum personal access tokens.**
2. **Short-lived JSON Web Tokens (JWTs)** for primary API access, validated by a custom guard.

This document summarizes the configuration that powers the stateless authentication flow now used across both the web client and external integrations.

## Getting Started

Install dependencies, run the migrations, and backfill the new trading calendar artefacts:

```bash
composer install
composer require guzzlehttp/guzzle:^7.9
php artisan migrate
php artisan trading-days:build --from=2015-01-01
php artisan trading-days:stats
php artisan trading-calendar:build --from=2015-01-01
# Optional holiday overlay
php artisan trading-calendar:build --from=2015-01-01 --holiday-file=storage/holidays.csv
```

## Token-Based Authentication

Rather than establishing browser sessions, the API issues bearer tokens that clients store and present on each request. Sanctum personal access tokens back both the refresh process and the custom JWT guard, allowing revocations to invalidate access immediately without relying on cookies or CSRF headers.

The high-level flow is:

1. Submit credentials against `POST /api/auth/login`.
2. Read the returned access (JWT) and refresh (Sanctum PAT) tokens.
3. Include `Authorization: Bearer <access_token>` on protected requests (for example `GET /api/v1/assets`).
4. Rotate tokens by calling `POST /api/auth/refresh` with the current refresh token when the access token nears expiration.
5. Call `POST /api/auth/logout` to revoke the refresh token (and therefore any JWT that references it).

No CSRF cookie or session negotiation is required for the login form or subsequent API requests.

### Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/auth/register` | Create a user account and return an initial JWT + refresh token. |
| `POST` | `/api/auth/login` | Exchange credentials for a JWT and refresh token. |
| `POST` | `/api/auth/refresh` | Rotate the refresh token and issue a new JWT. |
| `GET` | `/api/auth/me` | Retrieve the authenticated user's profile. Requires `Authorization: Bearer <token>`. |
| `POST` | `/api/auth/logout` | Revoke the provided refresh token (and invalidate linked JWTs). |
| `GET` | `/api/v1/...` | Versioned API endpoints that require a valid bearer token. |

### Token Fields

Every successful login and refresh returns:

```json
{
  "user": { "id": 1, "name": "Example", "email": "user@example.com" },
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "access_expires_at": "2025-01-01T12:00:00Z",
  "expires_in": 3600,
  "token_type": "Bearer",
  "refresh_token": "1|PLAIN_TEXT_REFRESH_TOKEN",
  "refresh_expires_at": "2025-01-15T12:00:00Z"
}
```

- `access_token` is the JWT used in the `Authorization` header.
- `expires_in` equals the configured `JWT_TTL` (default 3600 seconds).
- `refresh_token` is a Sanctum personal access token stored hashed in the database. Keep the plaintext value secure.

Revoking a refresh token (either manually or by calling `/api/auth/logout`) prevents the associated JWT from authenticating future requests because each JWT embeds the refresh token identifier (`rtid`).

### Example cURL Session

```bash
# Log in with an API user
curl -X POST https://api.localhost/api/auth/login \
  -H "Accept: application/json" \
  -d 'email=api@example.com' \
  -d 'password=secret'

# Use the returned access token
curl https://api.localhost/api/v1/assets/latest-prices \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}"

# Rotate tokens when the JWT is nearing expiration
curl -X POST https://api.localhost/api/auth/refresh \
  -H "Accept: application/json" \
  -d "refresh_token=${REFRESH_TOKEN}"
```

## Configuration

Key environment variables (see `.env.example`):

| Variable | Purpose |
| --- | --- |
| `SANCTUM_STATEFUL_DOMAINS` | Comma-delimited list of origins that should receive session cookies. |
| `SANCTUM_TOKEN_PREFIX` | Optional prefix applied to issued personal access tokens. |
| `JWT_SECRET` | Secret key used to sign JWTs (falls back to `APP_KEY` if unset). |
| `JWT_TTL` | Access-token lifetime in seconds (default 3600). |
| `JWT_REFRESH_TTL` | Refresh-token lifetime in seconds (default 14 days). |
| `JWT_LEEWAY` | Clock skew tolerance in seconds when validating tokens. |

## Running Tests

```bash
php artisan test --testsuite=Feature --filter=AuthenticationTest
```

Feature coverage includes registration, login, token refresh, logout, and guarded route access via both Sanctum and JWT.

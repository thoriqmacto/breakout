# Breakout API Authentication

The Breakout API exposes two complementary authentication strategies:

1. **Stateful sessions with Laravel Sanctum** for first-party browser and SPA clients.
2. **Stateless JSON Web Tokens (JWTs)** for third-party integrations and server-to-server access.

This document summarizes the configuration that powers both paths and the HTTP flows available to consumers.

## Sanctum SPA Authentication

Sanctum is configured for first-party requests that originate from the `apps/web` frontend. Stateful domains can be controlled through the `SANCTUM_STATEFUL_DOMAINS` environment variable (see `.env.example`). Requests from those origins receive encrypted cookies and CSRF protection.

The SPA flow mirrors the official Sanctum guidance:

1. Request the CSRF cookie: `GET /sanctum/csrf-cookie`.
2. Submit credentials against `POST /api/auth/login` with the `X-XSRF-TOKEN` header.
3. Subsequent API calls (for example `GET /api/v1/assets`) will authenticate via session cookies and the `auth:sanctum` guard.
4. Call `POST /api/auth/logout` to end the session and revoke the associated refresh token.

## JWT Authentication for External Clients

Programmatic consumers can request signed JWT bearer tokens and opt in to refresh-token rotation. Tokens are signed using HMAC (default `HS256`) and linked to Sanctum personal access tokens so that revocations take effect immediately.

### Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/auth/register` | Create a user account, start a Sanctum session, and return an initial JWT + refresh token. |
| `POST` | `/api/auth/login` | Exchange credentials for a JWT and refresh token. |
| `POST` | `/api/auth/refresh` | Rotate the refresh token and issue a new JWT. |
| `GET` | `/api/auth/me` | Retrieve the authenticated user's profile. Requires `Authorization: Bearer <token>` or Sanctum session cookies. |
| `POST` | `/api/auth/logout` | Revoke the provided refresh token (and active session, if present). |
| `GET` | `/api/v1/...` | Versioned API endpoints that require either a Sanctum session or a valid JWT. |

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

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
| `SB_SAVE_DISK` | Disk for Stockbit JSON payloads: `local` (default), `gdrive`, or `s3`. |
| `CSV_MIRROR_DISK` | Durable mirror disk for the OHLCV seed CSVs. Unset (default) disables mirroring. |
| `CSV_MIRROR_PATH` | Path prefix for the mirrored CSVs (default `seeds/historical`). |
| `GOOGLE_DRIVE_KEY_FILE` | Path to the Google service-account JSON. **Never commit this file.** |
| `GOOGLE_DRIVE_FOLDER_ID` | ID of the Drive folder shared with the service account. |
| `GOOGLE_DRIVE_ROOT` | Folder created inside the shared folder (default `breakout-data`). |

See [Storage Backends](../../README.md#storage-backends) in the root README for the service-account setup and the mirror commands. The database remains the query layer; Drive is durable cold storage only.

## Running Tests

```bash
php artisan test --testsuite=Feature --filter=AuthenticationTest
```

Feature coverage includes registration, login, token refresh, logout, and guarded route access via both Sanctum and JWT.

## Switching from SQLite to MariaDB

The `mariadb` connection is already defined in `config/database.php`; nothing
needs adding there. The steps below were run end to end against MariaDB 10.11.

**1. Install the PHP driver for the FPM runtime, not just the CLI.** The CLI and
FPM can be different builds — a working `php artisan migrate` proves nothing
about what nginx talks to, and a missing driver surfaces as a 500 from the app.

```bash
sudo apt-get install php8.2-mysql && sudo systemctl restart php8.2-fpm
php -r 'var_dump(extension_loaded("pdo_mysql"));'   # CLI
php-fpm8.2 -i | grep pdo_mysql                      # FPM
```

**2. Create the database with utf8mb4.**

```sql
CREATE DATABASE breakout CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'breakout'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON breakout.* TO 'breakout'@'localhost';
FLUSH PRIVILEGES;
```

**3. Point `.env` at it, then clear the config cache.**

```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=breakout
DB_USERNAME=breakout
DB_PASSWORD=...
```

```bash
php artisan config:clear
```

`config:clear` is not optional. Deploys run `config:cache`, which bakes the old
`DB_CONNECTION` into `bootstrap/cache/config.php`; without clearing it the app
keeps using SQLite while every command you run appears to succeed.

**4. Create the schema, then copy the rows.**

```bash
php artisan migrate
php artisan db:copy --from=sqlite --to=mariadb --pretend   # dry run first
php artisan db:copy --from=sqlite --to=mariadb
```

`db:copy` reads through the query builder rather than replaying a dump —
`sqlite3 .dump` emits `AUTOINCREMENT`, double-quoted identifiers and SQLite type
names that MariaDB rejects. It skips `migrations` (already populated by step 4)
along with cache, session and queue tables, and drops foreign key checks for the
duration so table order does not matter. Verify before cutting over:

```bash
php artisan db:copy --from=sqlite --to=mariadb --pretend   # should report 0 new rows
```

**5. Re-cache and restart.**

```bash
php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.2-fpm
```

### Things that differ from SQLite

- **Identifiers are capped at 64 characters.** Laravel derives index names from
  the table and column names, and SQLite accepts any length. Three indexes in
  this repo exceeded the cap and are now named explicitly. If you add a
  multi-column index, name it.
- **A failed migration does not roll back.** MariaDB DDL is not transactional,
  so a migration that dies partway leaves its tables behind and the retry fails
  with `Base table or view already exists`. Drop the debris, or use
  `migrate:fresh` on a database you are willing to lose.
- **`CAST(x AS REAL)` is a syntax error** (`ERROR 1064`). MariaDB wants `DOUBLE`.
  Raw SQL that names a type needs a `match` on the driver — see
  `StrategyScanCommand` and `TradingDaysStats` for the pattern.
- **The test suite still runs on SQLite** (`phpunit.xml` pins
  `DB_CONNECTION=sqlite`, `:memory:`). Green tests therefore do not prove a
  query works on MariaDB. Anything raw is worth exercising against both.

## Troubleshooting

### `The GET method is not supported for route api/auth/login. Supported methods: POST.`

This is raised when a **GET** reaches the front controller, so the request that
arrived at PHP was not the POST that was sent. `/api/auth/login` is registered
POST-only and nothing in the app rewrites the verb — confirm with:

```bash
php artisan route:list --path=auth
```

The verb is lost between the client and PHP. Send the request again **without
following redirects** — that single flag separates the two causes:

```bash
curl -i -X POST https://api.example.com/api/auth/login \
  -H 'Accept: application/json' \
  -d 'email=api@example.com' -d 'password=secret'
```

#### A `3xx` with a `Location:` header — a redirect ate the method

A 301 or 302 permits the client to re-issue the request as GET and drop the
body, and browsers, `curl -L`, and Postman's "follow redirects" all do. The
redirect is invisible in the error page because the client already followed it.

| `Location` differs by | Cause | Fix |
| --- | --- | --- |
| Scheme (`http:` → `https:`) | The `:80` server block, or Cloudflare "Always Use HTTPS" | `return 308` instead of `301`, and point clients at `https://` directly |
| Host (`www.` ↔ apex) | Canonical-host redirect in the vhost or DNS | Point clients at the canonical host |
| Trailing slash | `try_files $uri $uri/ …` — the `$uri/` makes nginx 301 to append a slash | Drop `$uri/`; nothing under `public/` should be served as a directory |

The durable fix is to have clients call the final URL rather than one that
redirects — a redirected POST costs a wasted round trip even when the status
code preserves the method. For the web app that means `NEXT_PUBLIC_API_URL` set
to the canonical scheme, host, and path, with no trailing slash.

Measured end to end against this app, through a real nginx and PHP-FPM:

```
through a 301:  final: GET  -> HTTP 405   (the error above, verbatim)
through a 308:  final: POST -> HTTP 500   (reaches AuthController::login)
```

#### A `405` straight away, no redirect

Then nothing redirected and the client really did send a GET — check the caller
before the server. `$_SERVER['REQUEST_METHOD']` going missing is *not* a
plausible explanation here: `Request::getMethod()` does default to `GET` when it
is absent, but the only realistic way to lose it on nginx is to drop
`include fastcgi_params;`, and that also drops `REQUEST_URI`. The observed
result is not a 405 — Laravel resolves every request as `/` and returns **200**
with the welcome page. A silent 200 on every endpoint is that misconfiguration's
signature; a 405 is not.

A known-good vhost lives at
[`deploy/nginx/breakout-api.conf`](../../deploy/nginx/breakout-api.conf). It is
IPv4-only as committed — `listen [::]:80;` on a host without IPv6 fails
`nginx -t` outright with *"socket() [::]:80 failed (97: Address family not
supported by protocol)"*.

> `public/.htaccess` also emits a method-preserving `308` for its trailing-slash
> rule, but nginx never reads `.htaccess` — that file only matters if the app is
> ever served by Apache.

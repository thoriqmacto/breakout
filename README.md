# Breakout

This repository contains the code for **Breakout**, structured as a multi-app monorepo. It currently includes a Laravel API (`apps/api`) and a Next.js front-end (`apps/web`).

## Technology
- PHP ^8.2 with Laravel ^12.0 as the web framework and tinker for interactive shells
- Next.js 15 with Tailwind CSS 4 for the front-end application

## Development
1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Install JavaScript dependencies:
   ```bash
   npm install
   ```
3. Copy `apps/api/.env.example` (or `apps/api/.env.local.example` for a local dev preset) to `apps/api/.env` and configure your environment. **Never commit `.env*` files with real credentials**: the repository's root and `apps/api` `.gitignore` files exclude all `.env` and `.env.*` variants except the `*.example` templates. If you accidentally paste a real API key (Stockbit bearer, Marketstack key, AWS, etc.) into a tracked file, rotate the key immediately.
4. Start the local development stack:
   ```bash
   composer dev
   ```
   This script runs the Laravel server, queue listener, log viewer, and Vite asset builder concurrently

## Available Commands
- Build frontend assets:
  ```bash
  npm run build
  ```
- Run the automated test suite:
  ```bash
  composer test
  ```
  The test script clears configuration and executes `php artisan test`

- Run the asset sync command with all options:
  ```bash
  php artisan asset:sync --check-python --run-python --import-csv --continue --chk-date=2025-08-01
  ```
  Or run with all confirmations accepted using today's date:
  ```bash
  php artisan asset:sync --eod
  ```

## Updating Asset Data (step-by-step)
The Laravel console already includes helpers for refreshing the trading calendar, syncing OHLCV data, and checking integrity. Run them in this order when updating datasets:

1. **Refresh the trading day calendar** – keeps `trading_days` aligned with Yahoo Finance and updates the seeder:
   ```bash
   php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
   ```
   Adjust the `--from` / `--to` range as needed; rerunning is idempotent and will rewrite `database/seeders/data/trading_days.php` when new rows are found.

2. **Sync OHLCV seeds and the database** – compare configured symbols to seed CSVs, optionally pull missing data via the Python scripts, and upsert bars into `price_bars`:
   ```bash
   php artisan asset:sync --eod
   ```
   This commands to auto-enable all required options with today's date and to skip prompts. The command will retrieve price data from Stockbit (Bearer token prompt required) and make Python downloader for Yahoo Finance as the backup.

   For a **single asset historical refresh**, use Stockbit scraper with `--historical` and rely on its default date window (IPO date to latest trading day):
   ```bash
   php artisan stockbit:scrape BBCA --historical
   ```
   Replace `BBCA` with your target symbol (for example `INCO` or `ANTM`).

3. **Verify and repair gaps or duplicates** – scan configured symbols and optionally resolve issues:
   ```bash
   php artisan ohlcv:check --all
   ```
   Simply checking across all tickers integrity of historical data with detail reports for each tickers.
   ```bash
   php artisan ohlcv:check --all --resolve=missing-days
   ```
   Provide a symbol to target a single asset instead of every configured index symbol:
   ```bash
   php artisan ohlcv:check INCO --resolve=missing-days
   ```
   Use `--resolve=extra-bars` to prune stray rows or `--resolve=missing-days --force-delete` when you want missing trading days removed from the calendar after attempted recovery.

## Feature Extraction Usage
Use the feature extraction command to persist daily OHLCV and broker metrics into `features_daily`.

- Extract features for the latest trading day per asset:
  ```bash
  php artisan features:extract
  ```
- Extract features for a single symbol on a specific date:
  ```bash
  php artisan features:extract --symbol=BBCA --date=2025-01-31
  ```
- Extract features for a date range (inclusive):
  ```bash
  php artisan features:extract --from=2025-01-01 --to=2025-01-31
  ```
- Limit how many symbols are processed in a run:
  ```bash
  php artisan features:extract --limit=10
  ```

### Feature Extraction Glossary
Below is a quick reference for the feature abbreviations emitted by `features:extract`, along with typical value ranges and meanings.

**OHLCV-derived features**
- `ret_1` — 1-day return `(close_t / close_{t-1}) - 1`. Range: -1.0+; 0 means unchanged, positive means up day, negative means down day.
- `range_pct` — intraday range `(high - low) / close`. Range: 0.0+ (percentage as a ratio).
- `close_pos` — close position within range `(close - low) / (high - low)`. Range: 0.0–1.0 (0 = closed at low, 1 = closed at high).
- `body_to_range` — candle body size relative to range `abs(close - open) / (high - low)`. Range: 0.0–1.0.
- `vol_ratio_20` — volume vs 20-day SMA `volume / SMA20(volume)`. Range: 0.0+ (1.0 = average volume).
- `atr_pct` — 14-day ATR scaled by close `ATR14 / close`. Range: 0.0+ (percentage as a ratio).
- `close_vs_ma20` — close vs 20-day SMA `(close / MA20) - 1`. Range: typically negative to positive; 0 means equal to MA20.
- `ma20_slope` — slope of the last 5 points of the MA20 series. Range: unbounded; positive = rising MA20.
- `breakout20` — 20-day high breakout flag. Range: 0 or 1 (1 = close above prior 20-day high).
- `compression` — volatility compression flag (ATR% below its 10-day SMA). Range: 0 or 1.

**Broker/flow features**
- `has_broker` — indicates broker data exists for the day. Range: 0 or 1.
- `turnover_value` — total traded value from Bandar detector. Range: 0.0+ (currency value).
- `turnover_volume` — total traded volume from Bandar detector. Range: 0+ (shares).
- `accdist_score` — accumulation/distribution score. Range: -1 (distribution), 0 (neutral/unknown), 1 (accumulation).
- `avg_net_norm` — average net value normalized by turnover value. Range: roughly -1.0 to 1.0; can exceed in edge cases when metrics are noisy.
- `avg5_net_norm` — 5-day average net value normalized by turnover value. Range: similar to `avg_net_norm`.
- `top1_net_norm` / `top3_net_norm` / `top5_net_norm` / `top10_net_norm` — top-N net value normalized by turnover value. Range: typically -1.0 to 1.0; magnitude reflects dominance.
- `buyer_count` — number of brokers with net buying. Range: 0+ (integer).
- `seller_count` — number of brokers with net selling. Range: 0+ (integer).
- `active_broker_count` — unique broker codes active that day. Range: 0+ (integer).
- `buyer_hhi` / `seller_hhi` — Herfindahl–Hirschman Index on buyer/seller shares. Range: 0.0–1.0 (higher = more concentration).
- `top1_sell_share` / `top3_sell_share` / `top5_sell_share` — share of total turnover contributed by top N net sellers. Range: 0.0–1.0.
- `net_to_gross_ratio` — absolute average net vs total gross value. Range: 0.0–1.0.
- `foreign_net_norm` / `local_net_norm` / `gov_net_norm` — net value by broker type normalized by turnover value. Range: typically -1.0 to 1.0; sign indicates net buy/sell.

**Cross/derived signals**
- `absorption_flag` — accumulation + bullish OHLCV setup. Range: 0 or 1.
- `dist_breakdown` — distribution + weak OHLCV setup. Range: 0 or 1.
- `stealth_acc` — accumulation + low-volatility setup. Range: 0 or 1.
- `bandar_dist_hard` — strong distribution risk flag. Range: 0 or 1.
- `valid_long_setup` — true when distribution flags are not triggered. Range: 0 or 1.

**Labels**
- `y_hit_5d` — classification label for +3.5% target within 5 trading days. Range: 1 (hit target), 0 (did not hit), null (insufficient data).
- `dd_5d` — reserved placeholder for drawdown (currently null).


## Accumulation Anomaly Scanner
Use this command to scan for potential accumulation setups using your idea: daily price down on lower volume, lower-timeframe absorption proxy (PBAS/absorption flag), and anchored broker-flow confirmation.

```bash
php artisan strategy:scan-accumulation --date=2026-04-08 --anchor-date=2026-01-01 --max-ret=-0.005 --max-vol-ratio=0.95 --min-pbas=70 --min-anchor-net-norm=0.01
```

Optional filters:
- `--symbol=BBCA --symbol=ANTM` (or comma-separated) to scan only selected tickers.
- `--limit=50` to expand result count.
- `--min-net-norm` to tighten same-day absorption proxy from broker net-flow.

## Storage Backends

Breakout keeps two different kinds of state, and they are stored differently on purpose.

| Layer | What lives there | Where it lives |
| --- | --- | --- |
| **Database** (`price_bars`, `features_daily`) | Every bar and feature the app queries | Always the relational DB. **This is the query layer and the source of truth.** |
| **File artifacts** (Stockbit JSON payloads, OHLCV seed CSVs) | Raw scrape payloads and the seed/export CSVs | Local disk by default; optionally mirrored to durable cold storage. |

Google Drive is **backup and cold storage only**. Nothing reads it to answer a query — it has no random access, no atomic rename, and a request quota, none of which suit a hot path. Moving a file artifact to Drive never moves a query off the database.

Everything below is opt-in. With the shipped defaults (`SB_SAVE_DISK=local`, `CSV_MIRROR_DISK` unset) the pipeline is entirely local and makes no remote calls, which is the intended development setup.

### The two file paths

**JSON payloads** (`broker_summary/`, `historical/`, `watchlist_eod/`) already write through `Storage::disk()`, so they follow a single setting:

```dotenv
SB_SAVE_DISK=gdrive   # local (default) | gdrive | s3
```

**OHLCV seed CSVs** (`database/seeders/data/historical/{SYMBOL}.csv`) are built on local disk and *mirrored* to the durable disk in batch:

```dotenv
CSV_MIRROR_DISK=gdrive   # empty (default) disables mirroring entirely
CSV_MIRROR_PATH=seeds/historical
```

They are not written straight to Drive. The CSV flow is read-existing → merge → write-back, looped per symbol and per date chunk; doing that against Drive would cost an API call per iteration, invite `403 rateLimitExceeded`, and lose the atomic temp-file + `rename()` that makes a half-written CSV impossible. Instead each data-mutating command hydrates the local CSVs from the mirror before its loop and pushes the changed ones after it:

```
hydrate  →  existing local read/merge/write loop (unchanged)  →  flush
```

`stockbit:scrape`, `asset:sync`, `ohlcv:check`, and `csv:fix-date-format` all accept `--disk=` to override `CSV_MIRROR_DISK` for a single run. Passing `--disk=` with an empty value forces a purely local run.

### Setting up a Google Drive service account

1. In the [Google Cloud console](https://console.cloud.google.com/), create (or pick) a project and **enable the Google Drive API**.
2. Create a **service account** and add a **JSON key**. Download it.
3. Save the key at `apps/api/storage/app/google/service-account.json`. This path is gitignored — the key is as sensitive as the Stockbit bearer, so **never commit it** and never place it anywhere tracked.
4. In Google Drive, create the folder that will hold the data and **share it with the service account's email** (`...@....iam.gserviceaccount.com`) with *Editor* access. A service account has no Drive storage quota of its own, so it must write into a folder shared with it.
5. Copy the folder ID out of its URL (`https://drive.google.com/drive/folders/<FOLDER_ID>`).
6. Fill in `apps/api/.env`:

   ```dotenv
   GOOGLE_DRIVE_KEY_FILE=storage/app/google/service-account.json
   GOOGLE_DRIVE_FOLDER_ID=<FOLDER_ID>
   GOOGLE_DRIVE_ROOT=breakout-data      # subfolder created inside the shared folder
   ```

7. Verify the credentials before wiring anything else up — the folder sharing is where most of the friction lives:

   ```bash
   cd apps/api
   php artisan test --filter=GoogleDriveDiskTest
   ```

   The round-trip test skips itself when the credentials are absent and runs a real `put`/`get`/`delete` against the shared folder when they are present.

### Migrating existing CSVs to the mirror

```bash
php artisan bars:mirror-push --disk=gdrive     # upload every local seed CSV
php artisan bars:mirror-push --disk=gdrive --symbol=BBCA --symbol=BBRI
php artisan bars:mirror-push --disk=gdrive --force   # re-upload even if unchanged
```

`bars:mirror-push` reports local and remote CSV counts and exits non-zero if any local CSV is missing from the mirror. **Compare those counts before treating the local copy as expendable.**

The inverse restores a machine (or a clean checkout) from the mirror:

```bash
php artisan bars:mirror-pull --disk=gdrive
php artisan bars:mirror-pull --disk=gdrive --force   # overwrite newer local files
```

By default a pull only fills in CSVs that are missing or older locally, so it will not clobber work in progress.

### Notes

- Repeat runs are cheap: a flush uploads only CSVs whose contents actually changed, tracked by a local hash manifest at `storage/app/bar-csv-mirror.json`. Delete that file (or use `--force`) to force a full re-upload.
- Mirror failures are logged and skipped, never fatal. By the time the flush runs, the database rows and local CSVs — the real output of a run — are already written.
- Throttling (`403 rateLimitExceeded`) and transient errors are retried with exponential backoff.
- The Stockbit bearer token store stays on its own local disk and is deliberately **not** routed to Drive.
- For a data pipeline, an S3-compatible store (Cloudflare R2, Backblaze B2) is technically a better fit than Drive, and `config/filesystems.php` already ships an `s3` disk. Because both go through the same Flysystem interface, switching is just `CSV_MIRROR_DISK=s3` / `SB_SAVE_DISK=s3`.

## CI / CD

Two workflows in `.github/workflows/`.

### `ci.yml` — runs on every pull request and push to `main`

| Job | Checks |
| --- | --- |
| **API — Laravel tests** | `php artisan test` on PHP 8.2, `composer check-platform-reqs`, `composer audit` |
| **Web — types, build, lint** | `tsc --noEmit`, `eslint`, `next build`, `npm audit --audit-level=critical` |
| **API — Pint** | `pint --test` |

All three block merges. The whole suite finishes in under a minute.

Two details worth knowing if you edit it:

- **PHP 8.2 is deliberate** — it is the floor `apps/api/composer.json` declares, and `config.platform.php` pins the resolver to it. Without that pin, running `composer update` on a newer machine silently produces a lockfile that cannot install on 8.2. `composer check-platform-reqs` in CI catches that drift.
- **No `.env` is written.** `phpunit.xml` already pins the test environment; only `APP_KEY` is missing and it is exported as a throwaway. `php artisan key:generate` cannot be used here — it boots `AppServiceProvider`, which resolves `JwtService`, which throws while `APP_KEY` and `JWT_SECRET` are both empty.

### `backend-deploy.yml` — deploys `apps/api` to the VPS

Triggered by `ci.yml` completing successfully on `main`, so a deploy can never ship a commit the tests rejected. It deploys the exact commit CI validated, not whatever `main` has become since. `workflow_dispatch` allows a manual re-deploy.

The web app is **not** covered here: Vercel builds and deploys `apps/web` from `main` on its own.

**The workflow is inert until you switch it on.** It is gated on a repository variable, so merging it changes nothing:

```
Settings → Secrets and variables → Actions → Variables
  DEPLOY_ENABLED = true
```

Required secrets, on a `production` GitHub Environment (`Settings → Environments → production`), which also lets you add required reviewers or a wait timer:

| Secret | Purpose |
| --- | --- |
| `DEPLOY_HOST` | VPS hostname or IP |
| `DEPLOY_USER` | SSH user owning the deploy directory |
| `DEPLOY_SSH_KEY` | Private key, full PEM contents. Use a dedicated deploy key, not a personal one |
| `DEPLOY_PATH` | Absolute path to the **repository root** on the server (the workflow `cd`s into `apps/api` itself) |
| `DEPLOY_PORT` | Optional, defaults to `22` |
| `DEPLOY_KNOWN_HOSTS` | Strongly recommended. Output of `ssh-keyscan -H <host>`. Without it the workflow falls back to trusting the host on first contact, which leaves the deploy key exposed to a man-in-the-middle |

What it runs on the server:

```bash
git fetch --prune origin && git reset --hard <the SHA CI validated>
php artisan down --retry=15
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up          # via a trap, so it runs even if a step above fails
```

Server-side prerequisites, one time: the repo cloned at `DEPLOY_PATH`, a complete `.env` in `apps/api` (`config:cache` bakes it in, so it must be correct before the first deploy), PHP and Composer on `PATH` for the deploy user, write access to `storage/` and `bootstrap/cache/`, and an nginx vhost pointing at `apps/api/public`.

The vhost is **not** deployed by the workflow — it is installed once by hand — but a known-good one is committed at [`deploy/nginx/breakout-api.conf`](deploy/nginx/breakout-api.conf). Two of its settings are load-bearing and worth not rediscovering: the `:80` block returns **308** rather than 301, and the PHP location does `include fastcgi_params;`. Either one wrong and every POST to the API arrives at Laravel as a GET, failing with `MethodNotAllowedHttpException` — see [Troubleshooting](apps/api/README.md#troubleshooting) in the API README.

Note `git reset --hard` discards anything uncommitted on the server — deliberate, so the deployed tree always matches the commit, but do not hand-edit files there.

**Do the first run manually** via *Actions → Deploy API → Run workflow*, and watch it. Deployment is the one part of this that cannot be rehearsed in CI.

## Repository Layout
```
.
├── README.md
├── deploy/
│   └── nginx/      Reference vhost for the API (installed by hand, not by CI)
└── apps/
    ├── api/        Laravel API
    │   ├── app/            Domain code (Models, Services, Console, etc.)
    │   ├── routes/         API routes (api.php), console commands
    │   ├── database/       Migrations, factories, seeders, seed data
    │   ├── resources/      Blade views, language files
    │   └── tests/          Feature and unit tests
    └── web/        Next.js app
        ├── app/            App Router routes and pages
        ├── components/     Shared React components
        ├── lib/            Utilities and helpers
        └── public/         Static assets
```

## Contributing
Pull requests are welcome. Please ensure tests pass before submitting your contribution.

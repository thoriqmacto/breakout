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

- Inspect the database-managed scheduler (see [Automation & Scheduling](#automation--scheduling)):
  ```bash
  php artisan scheduler:status          # every automation, its next run and last outcome
  php artisan schedule:list             # the single static entry that drives them
  php artisan scheduler:dispatch        # run whatever is due right now
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

On a configured deployment you rarely need to run this by hand: the seeded **Daily Analysis
Refresh** automation runs it every evening behind the scrapes, along with asset metrics, broker
rollups, watchlist scores and the saved strategies. See
[Automation & Scheduling](#automation--scheduling). The command below is still the way to rebuild
a specific symbol or an arbitrary range.

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

**OHLCV seed CSVs** (`{SYMBOL}.csv`) are built on local disk and *mirrored* to the durable disk in batch:

```dotenv
CSV_SEED_DIR=            # empty uses database/seeders/data/historical
CSV_MIRROR_DISK=gdrive   # empty (default) disables mirroring entirely
CSV_MIRROR_PATH=seeds/historical
```

> **On a server, set `CSV_SEED_DIR` to a path outside the working tree.**
>
> The default lives in `database/seeders/data/historical`, which is git-tracked
> — right for the committed bootstrap data, wrong for accumulating market data.
> The deploy runs `git reset --hard` in a persistent checkout, so every deploy
> rewrites those files back to whatever was committed and discards every bar the
> scheduler appended since. Point it somewhere the deploy cannot reach:
>
> ```dotenv
> CSV_SEED_DIR=/var/www/breakout-data/historical
> ```
>
> Moving an existing installation is a copy and a config cache rebuild:
>
> ```bash
> mkdir -p /var/www/breakout-data/historical
> cp database/seeders/data/historical/*.csv /var/www/breakout-data/historical/
> # add CSV_SEED_DIR to .env, then
> php artisan config:cache
> php artisan bars:mirror-push --disk=gdrive   # confirm local and Drive agree
> ```
>
> The committed CSVs stay where they are as bootstrap data for a fresh checkout.

They are not written straight to Drive. The CSV flow is read-existing → merge → write-back, looped per symbol and per date chunk; doing that against Drive would cost an API call per iteration, invite `403 rateLimitExceeded`, and lose the atomic temp-file + `rename()` that makes a half-written CSV impossible. Instead each data-mutating command hydrates the local CSVs from the mirror before its loop and pushes the changed ones after it:

```
hydrate  →  existing local read/merge/write loop (unchanged)  →  flush
```

`stockbit:scrape`, `asset:sync`, `ohlcv:check`, and `csv:fix-date-format` all accept `--disk=` to override `CSV_MIRROR_DISK` for a single run. Passing `--disk=` with an empty value forces a purely local run.

### Setting up Google Drive OAuth for a personal Gmail account

Authentication is OAuth 2.0 as a real Google user, not a service account. A service account cannot be used here: Google gives them no storage quota, so one can create folders in My Drive quite happily and then fail on the first actual file, which makes the failure look like a permissions problem when it is not.

1. In the [Google Cloud console](https://console.cloud.google.com/), create (or pick) a project and **enable the Google Drive API**.
2. Configure the **OAuth consent screen**. Add the Gmail account that will own the files as a test user if the app stays in Testing.
3. Create an **OAuth 2.0 Client ID** of type **Web application**.
4. Add `https://developers.google.com/oauthplayground` as an **authorized redirect URI** — this is what lets the Playground mint the refresh token below.
5. Open the [OAuth 2.0 Playground](https://developers.google.com/oauthplayground/), and in the gear menu tick **Use your own OAuth credentials**, pasting the client ID and secret.
6. In step 1 enter the scope `https://www.googleapis.com/auth/drive` — the full Drive scope. `drive.file` and the read-only scopes are not enough for the mirror.
7. Authorize as the Gmail account that should own the backups, then **Exchange authorization code for tokens**.
8. Copy the **refresh token**. That is the credential the VPS uses; it lets scheduled and CLI jobs authenticate with no browser.
9. Fill in `apps/api/.env` on the server:

   ```dotenv
   GOOGLE_DRIVE_CLIENT_ID=xxxx.apps.googleusercontent.com
   GOOGLE_DRIVE_CLIENT_SECRET=xxxx
   GOOGLE_DRIVE_REFRESH_TOKEN=xxxx

   GOOGLE_DRIVE_FOLDER_ID=              # blank = My Drive root
   GOOGLE_DRIVE_ROOT=breakout-data

   CSV_MIRROR_DISK=gdrive
   CSV_MIRROR_PATH=seeds/historical
   ```

   Those values are placeholders. `GOOGLE_DRIVE_CLIENT_SECRET` and `GOOGLE_DRIVE_REFRESH_TOKEN` are credentials — never commit them, and there is no longer any JSON key file to protect.

   Leaving `GOOGLE_DRIVE_FOLDER_ID` blank puts everything under `My Drive/breakout-data`. Set it only to nest the app folder inside an existing folder, and then it must hold just the id from that folder's URL.

10. **While the consent screen is in Testing, refresh tokens expire after seven days.** For a VPS that should keep working, publish the app in the Google Cloud console. This is the single most common cause of Drive working for a week and then failing with `invalid_grant`.

11. Apply the configuration and verify:

    ```bash
    cd apps/api
    php artisan optimize:clear
    php artisan config:cache      # config:cache bakes .env in; clearing first is required
    php artisan gdrive:check
    ```

    `gdrive:check` reports each credential as `set` or `unset` — never their values — then writes, reads back, overwrites and deletes a probe file, naming whichever step fails. `--keep` leaves the probe in place so you can see it in Drive.

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
- When a local CSV has diverged from what was last mirrored, **bar coverage decides which copy wins, not modification time**. A `git reset --hard` rewrites a tracked CSV with a fresh mtime, so a file that had just lost a month of bars looked *newer* than the Drive copy still holding them — hydrate stood down and the following flush pushed the truncated file over the good backup. The copy with more rows now wins in both directions, so a run that extended a CSV and died before flushing also keeps its extra bars. `--force` still overrides for disaster recovery.
- Mirror failures are logged and skipped, never fatal. By the time the flush runs, the database rows and local CSVs — the real output of a run — are already written.
- Throttling (`403 rateLimitExceeded`) and transient errors are retried with exponential backoff.
- The Stockbit bearer token store stays on its own local disk and is deliberately **not** routed to Drive.
- For a data pipeline, an S3-compatible store (Cloudflare R2, Backblaze B2) is technically a better fit than Drive, and `config/filesystems.php` already ships an `s3` disk. Because both go through the same Flysystem interface, switching is just `CSV_MIRROR_DISK=s3` / `SB_SAVE_DISK=s3`.

## Automation & Scheduling

Scheduled market-data work lives in the database, not in `routes/console.php`. Automations
can be created, edited, enabled, disabled, deleted and run on demand from
**`/dashboard/automation`**, and take effect on the next minute — no deploy required.

### Timezone

Every schedule and every market-day calculation is evaluated in **`Asia/Jakarta` (WIB, UTC+7)**,
because that is the market this system follows.

`config/app.php` still says `UTC`, and that is deliberate: timestamps are *stored* in UTC and the
application's global timezone is not changed to make the scheduler work. What is Jakarta is the
*interpretation* — `0 16 * * *` on a task whose timezone is `Asia/Jakarta` fires at 16:00 WIB
(09:00 UTC) regardless of what the server clock is set to, and "today" for a trading-day
condition is the Jakarta date, not the server's.

Override with `AUTOMATION_TIMEZONE` if you ever need to; each task also carries its own
`timezone` column.

### Architecture

```
cron (every minute)
  └─ php artisan schedule:run
       └─ scheduler:dispatch                 ← the ONE static entry in routes/console.php
            ├─ reads enabled rows from `scheduled_tasks`, in priority order
            ├─ works out which occurrences are due, in each task's own timezone
            ├─ evaluates the task's market condition against `trading_calendar`
            ├─ takes a per-task lock, and a shared lock for bulk Stockbit jobs
            └─ Artisan::call(<allowlisted command>, <structured parameters>)
                 └─ writes a `scheduled_task_runs` row: status, timings, output, metadata
```

The market-day conditions read `trading_calendar`, and the seeded
`automation:trading-calendar-refresh` task is what keeps that table current — it runs earliest
and at the front of the priority order, so the jobs that depend on it always see a fresh
calendar. See *Keeping the trading calendar current* below.

There is exactly one static scheduler entry. A second scheduling mechanism alongside the
database one would mean two places to look when a job does not run.

The previous hard-coded `asset:sync --eod` at 15:00 `Asia/Qatar` was **removed** — it competed
with the database-managed daily OHLCV sync, which runs at 16:00 WIB and only on days the IDX
actually traded.

### Required production setup

Cron must invoke Laravel's scheduler every minute. Add this to the deploy user's crontab:

```cron
* * * * * cd /var/www/breakout/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

`scheduler:dispatch` runs due tasks in-process, in priority order, so a bulk scrape holds the
tick until it finishes. It is registered `withoutOverlapping()->runInBackground()`, so the next
minute's `schedule:run` returns immediately rather than queuing up behind it.

**"Run now" from the dashboard is queued**, so a queue worker must be running for that button to
do anything (scheduled runs do not need one):

```ini
; /etc/supervisor/conf.d/breakout-worker.conf
[program:breakout-worker]
command=php /var/www/breakout/apps/api/artisan queue:work --sleep=3 --tries=1 --timeout=7200
directory=/var/www/breakout/apps/api
user=www-data
autostart=true
autorestart=true
stopwaitsecs=7300
redirect_stderr=true
stdout_logfile=/var/log/breakout-worker.log
```

`--tries=1` is intentional: the runner records the outcome on the run row, so a retry would
re-execute an hour of API calls that already reported how they went.

### Default seeded automations

Installed by the migrations that create the tables, and restorable with
`php artisan db:seed --class=AutomationSeeder`. All five are fully editable from the dashboard.

| Name | Schedule | Condition | Priority | Command |
| --- | --- | --- | --- | --- |
| Trading Calendar Refresh | 17:30 `Asia/Jakarta`, daily | `none` | 1 | `automation:trading-calendar-refresh` |
| Stockbit Token Reminder | 09:00 `Asia/Jakarta`, daily | `none` | 5 | `automation:token-check` |
| Daily OHLCV Sync | 18:00 `Asia/Jakarta`, daily | `trading_day` | 10 | `automation:ohlcv-daily` |
| Daily Broker Summary | 18:00 `Asia/Jakarta`, daily | `trading_day` | 20 | `automation:broker-summary-daily` |
| Daily Analysis Refresh | 18:00 `Asia/Jakarta`, daily | `none` | 30 | `automation:analysis-refresh` |

In plain language:

- **Every day at 17:30 WIB** → refresh the trading calendar, so the conditions below have
  something current to read. This runs first, on holidays too — a holiday is precisely when the
  calendar needs to record that it was one.
- **Every valid IDX trading day at 18:00 WIB** → update OHLCV for every asset with
  `sync_price = true`, then bring every asset with `sync_broker_summary = true` up to the latest
  valid trading day. The two scrapes never run at the same time: priority orders them and both
  take a shared Stockbit lock, so the broker summary queues behind the OHLCV sync.
- **Then, in the same pass** → recompute everything derived from what just landed:
  `features_daily`, asset metrics, broker accumulation rollups, watchlist scores and the saved
  rule-builder strategy runs. Priority 30 puts it last, so it always sees the day's imports.
- **After successful persistence** → mirror the corresponding files to Google Drive.
- **Every day at 09:00 WIB** → inspect the Stockbit token and warn when renewal is needed.

The three 18:00 jobs run **in one dispatcher pass, in priority order, in the same process** —
that is what makes "after" reliable. A separate later cron time would not: `scheduler:dispatch`
is registered `withoutOverlapping()`, so a later occurrence arriving while the scrapes are still
running would be dropped rather than queued.

**Why the analysis refresh has no market-day condition.** It reads only what is already stored
and costs no API quota, and it is a no-op when nothing has changed. On a day the calendar cannot
confirm — when the two scrapes skip — it still picks up data that arrived late, instead of
skipping alongside them.

**Why 18:00 and not 16:00.** The calendar is derived from Yahoo's published `^JKSE` bars, so it
cannot confirm that today traded until that bar exists. 16:00 WIB is the closing bell itself,
which is too early for that to be true.

### Daily broker summaries and backfill

`automation:broker-summary-daily` resolves its range **per asset**, not globally:

```
from = the first trading day after that asset's newest stored window
to   = the latest day `trading_calendar` records as having actually traded
```

In the steady state those are the same date, so the window is a single day and reaches
`broker_summary_facts` and `broksums` like any daily import. After a gap they are not, and the
gap is fetched as **one aggregate covering it** — one request per asset rather than one per
missing session. That is what makes an asset sitting on a three-month aggregate, an asset added
last week, and an asset collected yesterday all correct on the same run, with no separate
backfill mode to remember.

Assets needing the same range are grouped into one scrape invocation, so a normal evening is a
single call covering every ticker. An asset already current is skipped without an API call.

Two bounds keep a first run sane:

- `--max-backfill-days` (default 120) caps how long a gap one run will request as a single
  aggregate. A longer gap is walked forward a slice per night. An explicit `--from` is exempt.
- A resumed range is snapped forward to the next actual session. The day after a Friday window is
  a Saturday, and asking for `2026-08-29..2026-08-31` would file Monday's flow as a three-day
  range that never reaches the daily projections.

`to` is deliberately the last *observed* trading day and not today: on a trading evening before
Yahoo publishes, the calendar still ends yesterday, and fetching through today anyway would file
a partial session as a complete one. The lag is reported as `days_behind` and closes itself on
the next run.

Overlapping ranges are safe by construction. A window is keyed on
`(asset, from_date, to_date, transaction_type)`, so a backfill aggregate and the days around it
are separate rows that never overwrite each other, and re-running a range converges instead of
duplicating. `BrokerWindowResolver` never sums overlapping windows into one rollup.

`automation:broker-summary-weekly` still exists and still works if you want a week on purpose,
but it is no longer part of the seeded schedule — the migration disables the seeded row rather
than deleting it, so its run history survives.

```bash
php artisan automation:broker-summary-daily                        # what the scheduler runs
php artisan automation:broker-summary-daily --tickers=BBCA --tickers=BRPT
php artisan automation:broker-summary-daily --from=2026-01-01      # force a start for every ticker
php artisan automation:broker-summary-daily --max-backfill-days=30 # ask for a smaller first slice
php artisan automation:broker-summary-daily --no-import --no-mirror
```

### What the analysis refresh rebuilds

`automation:analysis-refresh` runs five steps in dependency order, each individually skippable:

| Step | Command | Reads | Writes |
| --- | --- | --- | --- |
| features | `features:extract` | prices, broker windows | `features_daily` |
| metrics | `asset:metrics --all --persist` | prices, `features_daily` | `metrics` |
| rollup | `strategy:rollup-broker-accumulation` | broker windows | `broker_accumulation_windows` |
| watchlist | `strategy:rank-watchlist` | `features_daily`, `metrics`, rollups | `watchlist_scores` |
| strategies | `strategy:run` | `features_daily` | `strategy_runs` |

It also catches up rather than recomputing only today: it re-extracts from the newest date
features already exist for through the newest date a price bar exists for, bounded by
`--max-days` (default 10). The newest computed day is deliberately rebuilt too — the broker
summary for a day is imported after the bars, so that day's features are the ones most likely to
have been built from incomplete inputs.

The scan date follows the **data**, not the clock: it is the newest date a price bar exists for.
Claiming today when the scrape has not landed would date every derived row wrongly.

A failing step is recorded on the run and the rest still run. These outputs are independent
enough that abandoning the remaining four because the first struggled would leave more of the
dashboard stale, not less. Every step is an upsert, so running it twice changes nothing.

```bash
php artisan automation:analysis-refresh                      # what the scheduler runs
php artisan automation:analysis-refresh --date=2026-08-28    # one specific day
php artisan automation:analysis-refresh --max-days=30        # widen the catch-up window
php artisan automation:analysis-refresh --symbol=BBCA        # one ticker through every step
php artisan automation:analysis-refresh --skip-strategies    # any step can be left out
```

### Keeping the trading calendar current

Every market-day condition reads `trading_calendar`, and nothing else advances it. Because a
missing row means *unknown* rather than *closed* (see below), a calendar that stops advancing
does not fail loudly — it makes every dependent automation skip, quietly, forever. That is what
`automation:trading-calendar-refresh` exists to prevent.

Each run imports recent trading days from Yahoo, then rebuilds the calendar. It holds one rule:

> Never write a calendar row for a date beyond the last day Yahoo actually has a bar for.

`TradingCalendarBuilder` decides by absence — a weekday with no `trading_days` row becomes
`is_holiday = true`. That is correct for a date the market has already traded past, and wrong
for one it has not reached yet. Rebuilding through "today" before today's bar is published would
positively record today as a holiday, and the conditions would then skip with a confident
`not_trading_day` instead of an honest `trading_calendar_incomplete`. So the range is clamped to
the last observed trading date and never guesses past it.

The consequence is that the calendar always trails the market by however long Yahoo takes to
publish. That lag is reported as `days_behind` on every run rather than hidden, and a lag beyond
five days marks the run partial — it means the import has been failing and every market-day
condition is skipping as a result.

```bash
php artisan automation:trading-calendar-refresh                  # what the scheduler runs
php artisan automation:trading-calendar-refresh --lookback=365   # widen the rebuild window
php artisan automation:trading-calendar-refresh --skip-import    # rebuild without calling Yahoo
```

A failed Yahoo import is reported but not fatal: the calendar is still rebuilt from the trading
days already stored, because losing that because the network was down would be the worse outcome.

For a first run, or after a long outage, seed the history directly:

```bash
php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
php artisan trading-calendar:build --from=2015-01-01 --to=$(date +%F)
```

### How trading-day conditions work

Conditions are answered from the `trading_calendar` table, never from the shape of the week.

- **`none`** — runs on every occurrence.
- **`trading_day`** — resolves today in `Asia/Jakarta` and runs only if that date's row exists
  and `is_trading_day = true`.
- **`last_trading_day_of_week`** — takes the Monday–Sunday week containing today, lists the
  dates in it with `is_trading_day = true`, and runs when today equals the last of them. The
  first and last of those dates become the weekly scrape's `--from` and `--to`.

A Monday holiday moves `from` to Tuesday; a Friday holiday moves `to` to Thursday. Neither is
assumed — both come from the calendar.

**Missing rows are not treated as holidays.** A date with no row is *unknown*, and to a plain
query that is indistinguishable from "closed" — which would make Thursday look like the week's
final trading day every time the calendar fell behind, and file a Monday–Thursday range as the
week's summary. So an incomplete week produces a skipped run with reason
`trading_calendar_incomplete`, surfaced in the Automation history and on the status header. A
week containing no trading day at all is skipped as `no_trading_days_in_week`.

#### The weekly catch-up

`last_trading_day_of_week` is no longer used by a seeded automation — the broker summary is
collected daily now — but the condition remains available, and this is how it behaves.

That honesty has a cost, and the catch-up is what pays it.

When Friday is a holiday, Thursday is the week's last trading day — but standing on Thursday the
calendar has no row for Friday yet, so it cannot know that, and correctly refuses to guess. Left
there, that week would never be summarised at all.

So `last_trading_day_of_week` also passes on the **first trading day of a new week**, when the
previous week is by then fully settled. The weekly job then summarises that previous week
retrospectively, using its true `from`..`to`. Nothing predicts the future: both cases are decided
from rows that already exist.

Two guards keep this from misfiring:

- Only the week's *opening* trading day catches up, so a Wednesday does not keep re-proposing a
  week Monday already handled.
- Before scraping, the job checks whether a `BrokerSummaryWindow` already exists for that exact
  range. If it does, the run records `week_already_summarised` and costs one query. Asking the
  canonical record rather than the run history means this survives the task being renamed,
  recreated, or run by hand.

The normal Friday path is untouched, and re-running it deliberately is still supported: the
importer is idempotent, so it repairs a bad import rather than duplicating one.

### Safe execution — no shell, no remote command injection

The dashboard can schedule commands, but it can never describe a command *line*. A
`scheduled_tasks` row stores an Artisan command **name** from the allowlist in
`config/automation.php` plus a structured `{arguments, options}` map, and both are validated on
write and again before execution. Execution goes through `Artisan::call()`; nothing is
concatenated, quoted, or handed to a shell — there is no `exec()`, `shell_exec()`, `proc_open()`
or `Process` anywhere on this path.

Each allowlisted command declares its acceptable parameters and their types (`boolean`,
`integer`, `date`, `enum`, `string` with a pattern, `symbol_list`). Anything undeclared, or a
value of the wrong shape, is a 422.

**The Stockbit bearer can never be stored as a task parameter.** `stockbit:scrape` is
allowlisted *without* `--token`, and a name-level blocklist (`token`, `bearer`, `secret`,
`password`, `api-key`, …) rejects it regardless of what the config says. The token is resolved at
execution time from the existing encrypted store.

To allow another command, add it to `config/automation.php` under `commands`.

### Stockbit token lifecycle

The existing token system remains the source of truth: `StockbitTokenResolver`,
`StockbitTokenStore` (encrypted, on the local disk, never mirrored to Drive),
`StockbitExodusClient::jwtExpiresAt()`, `stockbit:token:set` and `stockbit:token:status`. No
second plaintext field was added anywhere, and no API returns the bearer.

Every scheduled bulk Stockbit job **preflights the token before it starts**, and reports one of:

| State | Meaning |
| --- | --- |
| `healthy` | Present, and comfortably above the warning threshold. |
| `expiring_soon` | Present, but under `AUTOMATION_STOCKBIT_WARN_TTL_MINUTES` (default 720). |
| `expired` | Present but past its `exp`. |
| `missing` | Nothing stored. |
| `expiry_unknown` | Present, with no readable `exp` claim. Bulk jobs are allowed. |

A bulk job additionally requires at least `AUTOMATION_STOCKBIT_MIN_TTL_MINUTES` (default 90) of
remaining lifetime. A token that expires twenty minutes into an hour-long scrape is not usable,
and discovering that halfway through leaves a partial import behind. When the preflight fails,
nothing is called: the run is recorded as **`blocked_token`** with a reason naming the remedy,
and a persistent dashboard alert is raised.

Renewing:

```bash
php artisan stockbit:token:status     # source, fingerprint, expiry — never the token
php artisan stockbit:token:set        # paste, pipe, or --from-clipboard
```

…or from the token card on `/dashboard/automation`, which accepts a pasted JWT (a leading
`Bearer ` is tolerated), rejects one that is already expired, persists through the same
encrypted store, and clears the reminder.

There is deliberately **no automated credential login or browser automation**. Renewal is a
person pasting a token; what the automation can usefully do is notice early and say so.

Because this project has no mail or push transport — and one token reminder is not a good reason
to add a third-party notification dependency — the reminder is a row in `automation_alerts`,
shown in the authenticated dashboard header on every page and on the Automation page. It is
keyed on `(type, key)`, so a daily check updates one row instead of accumulating one per day, and
a healthy token clears it.

### Google Drive cold-storage synchronisation

Two collections, one authoritative mirror path each — deliberately not two layers pushing the
same files.

**OHLCV seed CSVs.** `stockbit:scrape` already hydrates before its read/merge/write loop and
flushes the CSVs it touched afterwards, via `BarCsvMirror` (`CSV_MIRROR_DISK`). The daily
automation delegates to that rather than mirroring again, and reports the result on the run row.
Only the tickers touched during the run are pushed, and a hash manifest means an unchanged file
is not re-uploaded.

**Broker-summary JSON.** New: `BrokerSummaryArchiveMirror`, plus a `broker-summary:mirror-push`
command matching `bars:mirror-push`.

```dotenv
BROKER_SUMMARY_MIRROR_DISK=gdrive   # defaults to CSV_MIRROR_DISK; empty disables mirroring
```

```bash
php artisan broker-summary:mirror-push --disk=gdrive
php artisan broker-summary:mirror-push --disk=gdrive --since=2026-08-01
```

It preserves the path under `stockbit.save_dir`, compares **content** rather than filenames,
verifies the remote copy after writing, retries throttling with backoff, and **never deletes or
modifies the local file** — a successful upload changes nothing locally, and a failed one leaves
the only good copy exactly where it was. The daily and weekly jobs mirror only the JSON they
just wrote, and only after that JSON has been imported.

A Drive failure is reported on the run (`gdrive` / `gdrive_broker_summary` in run metadata, shown
in the history) and never fails the data run. Local market data is never destroyed because cold
storage was unreachable.

### Broker-summary import

The scheduled jobs import **only the files they just produced**, via
`BrokerSummaryImporter::importPaths()`. They do not run a full `broker-summary:rebuild` over the
whole archive every evening — a cost that grows with every day the system runs.

`importFromDisk()` and `broker-summary:rebuild` are unchanged and remain the full-archive
recovery path.

Import is idempotent: a window is keyed on `(asset, from_date, to_date, transaction_type)` and
its entries are replaced wholesale, so re-running a range converges rather than duplicating. It
is also why a daily window and an overlapping backfill aggregate coexist as separate rows instead
of overwriting each other.

**A multi-day Stockbit response is one aggregate over `from..to`** and is stored as exactly that
— a multi-day `BrokerSummaryWindow`. It is never written into the day-shaped legacy tables
(`broker_summary_facts`, `broksums`) stamped with Monday's or Friday's date. That rule is
unchanged and is covered by tests.

### Inspecting and diagnosing

```bash
php artisan schedule:list             # the single static entry that drives everything
php artisan scheduler:status          # every database task: schedule, next run, last outcome
php artisan scheduler:status --all    # including disabled ones
php artisan scheduler:dispatch        # run whatever is due right now
php artisan scheduler:dispatch --at="2026-08-28T09:00:00Z"   # evaluate as at a given moment
php artisan stockbit:token:status     # token source, fingerprint and expiry
php artisan automation:trading-calendar-refresh --skip-import   # rebuild the calendar in place
```

Diagnosing a missed run:

1. **A `skipped` run exists** → the scheduler fired and the market condition said no. The
   `skip_reason` says which: `not_trading_day`, `not_last_trading_day_of_week`,
   `trading_calendar_incomplete`, `week_already_summarised`, `overlapping_run`,
   `stockbit_busy`, …
   - `trading_calendar_incomplete` on *every* run means the calendar has stopped advancing.
     Check the latest `trading-calendar-refresh` run: its `days_behind` says how far it has
     fallen, and its `trading_days_import` status says whether Yahoo is reachable.
2. **A `blocked_token` run exists** → the token preflight failed. Renew it; nothing was scraped.
3. **No run at all** → the dispatcher never fired. Check that cron is invoking
   `php artisan schedule:run` every minute, and compare *Last dispatched run* on the Automation
   status header against the wall clock.
4. **Status `success` with a `Partial` badge** → the run completed but did not do everything;
   the run detail names the tickers that produced no bar, or the uploads that failed.
5. **Run history is empty and the task shows "Never run"** → confirm the task is enabled and its
   cron expression is valid (`scheduler:status` prints `invalid cron` when it is not).

Structured logs are written under `automation.task.started`, `automation.task.finished`,
`automation.dispatch.*`. Captured Artisan output is redacted of anything credential-shaped and
truncated to `AUTOMATION_MAX_OUTPUT_LENGTH` characters (default 20,000, keeping the tail) before
it is stored, so run history cannot grow without bound and a leaked bearer never reaches a
browser.

### Reliability guarantees

| Concern | How it is handled |
| --- | --- |
| Same task overlapping itself | Per-task cache lock for the whole execution. |
| Two bulk Stockbit jobs at once | Shared `automation:stockbit-bulk` lock; the second waits. |
| The same occurrence running twice | Unique index on `(scheduled_task_id, scheduled_for)`. |
| A missed minute | Catch-up window (`AUTOMATION_CATCH_UP_MINUTES`, default 5), still duplicate-safe. |
| Repeated imports | Windows keyed on the range; entries replaced, not appended. |
| Drive unreachable | Reported on the run; local files untouched; the run still succeeds. |
| Token unusable | `blocked_token` before any API call, plus a persistent alert. |
| Calendar unreadable or incomplete | Skip with a named reason. Never a guess. |
| Calendar falling behind | Refreshed daily; the lag is reported as `days_behind`, never hidden. |
| A weekday the market has not reached | Never written, so it can never be mistaken for a holiday. |
| A week that closed on an unconfirmable day | Caught up on the next week's first trading day. |
| Secrets | No bearer in the scheduler DB, in run output, in logs, or in any API response. |
| Shell injection | No shell. Allowlisted command names and validated structured parameters only. |

### Automation environment variables

```dotenv
AUTOMATION_TIMEZONE=Asia/Jakarta
AUTOMATION_CATCH_UP_MINUTES=5
AUTOMATION_DISPATCH_BUDGET_SECONDS=3300
AUTOMATION_TASK_LOCK_SECONDS=7200
AUTOMATION_STOCKBIT_LOCK_SECONDS=7200
AUTOMATION_STOCKBIT_LOCK_WAIT_SECONDS=3000
AUTOMATION_MAX_OUTPUT_LENGTH=20000
AUTOMATION_STOCKBIT_MIN_TTL_MINUTES=90
AUTOMATION_STOCKBIT_WARN_TTL_MINUTES=720
BROKER_SUMMARY_MIRROR_DISK=gdrive
```

### Deploying this feature

```bash
php artisan migrate --force        # creates the tables and installs the four automations
php artisan config:clear

# Seed the calendar history once. Until trading_calendar covers the current
# week, every market-day condition honestly skips as trading_calendar_incomplete.
php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
php artisan trading-calendar:build --from=2015-01-01 --to=$(date +%F)

# then add the crontab entry above, and a queue worker if you want "Run now"
```

No secrets belong in any of the above. The Stockbit token is set once with
`php artisan stockbit:token:set` (or from the dashboard) and lives encrypted on the local disk.

### API

All under `/api/v1`, behind the existing `auth:sanctum,jwt` middleware.

| Method | Path |
| --- | --- |
| `GET` | `/v1/scheduled-tasks` |
| `POST` | `/v1/scheduled-tasks` |
| `GET` | `/v1/scheduled-tasks/{id}` |
| `PUT` / `PATCH` | `/v1/scheduled-tasks/{id}` |
| `DELETE` | `/v1/scheduled-tasks/{id}` |
| `POST` | `/v1/scheduled-tasks/{id}/toggle` |
| `POST` | `/v1/scheduled-tasks/{id}/run` |
| `GET` | `/v1/scheduled-tasks/{id}/runs` |
| `GET` | `/v1/automation/status` |
| `GET` | `/v1/automation/runs` |
| `GET` | `/v1/automation/alerts` |
| `DELETE` | `/v1/automation/alerts/{id}` |
| `GET` | `/v1/automation/stockbit-token` |
| `PUT` | `/v1/automation/stockbit-token` |
| `DELETE` | `/v1/automation/stockbit-token` |

`GET /v1/automation/stockbit-token` returns status only — configured, source, fingerprint
(`****abcd`), expiry, remaining duration. There is no endpoint that returns the bearer.

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

The vhost is **not** deployed by the workflow — it is installed once by hand — but a known-good one is committed at [`deploy/nginx/breakout-api.conf`](deploy/nginx/breakout-api.conf). Two of its settings are load-bearing and worth not rediscovering:

- The `:80` block returns **308**, not 301. A 301 lets the client re-issue the request as GET without its body, so every POST to the API arrives at Laravel as a GET and fails with `MethodNotAllowedHttpException`.
- The PHP location does `include fastcgi_params;`. Without it `REQUEST_URI` never reaches PHP, Laravel routes everything to `/`, and the whole API silently answers 200 with the welcome page.

It is also IPv4-only as committed — uncomment the `listen [::]` lines only if the host has IPv6, since they fail `nginx -t` outright otherwise. See [Troubleshooting](apps/api/README.md#troubleshooting) in the API README for the diagnostic.

Note `git reset --hard` discards anything uncommitted on the server — deliberate, so the deployed tree always matches the commit, but do not hand-edit files there.

**Do the first run manually** via *Actions → Deploy API → Run workflow*, and watch it. Deployment is the one part of this that cannot be rehearsed in CI.

A queue worker is a separate, ongoing concern: `queue:restart` above only signals a running worker to pick up new code, it does not start one. Rule-builder strategy runs are queued (`QUEUE_CONNECTION=database`), so without a supervised `php artisan queue:work` in `apps/api` they stay in `queued` forever.

#### The deploy fails in about five seconds

That is the `Configure SSH` step, before anything reaches the server. Open the failed run and expand it — the step prints its resolved environment:

```
env:
  DEPLOY_SSH_KEY:
  DEPLOY_KNOWN_HOSTS:
  DEPLOY_HOST:
  DEPLOY_PORT: 22
```

Blank values mean the secrets are not readable by the job. GitHub renders a set secret as `***`, never as an empty string, so empty is proof they are missing rather than wrong. `DEPLOY_PORT: 22` is the workflow's own `|| '22'` default and says nothing either way.

The job runs at all only when `DEPLOY_ENABLED` is `true`, so reaching this step means the variable is set and the secrets are not. They must live either on the **`production` environment** (`Settings → Environments → production → Environment secrets`) or on the repository (`Settings → Secrets and variables → Actions`) — an environment-scoped job reads both, but a secret added to a *different* environment is invisible to it.

With an empty `DEPLOY_HOST` the fallback `ssh-keyscan` exits non-zero, and the step runs under `bash -e`, which is why the whole job stops there with exit code 1 rather than continuing to a clearer error.

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

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

## Repository Layout
```
.
├── README.md
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

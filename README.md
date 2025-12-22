# Breakout

This repository contains the code for the **Breakout** API service. The project is structured as a multi-app monorepo. At present it includes a single Laravel application located in `apps/api`.

## Technology
- PHP ^8.2 with Laravel ^12.0 as the web framework and tinker for interactive shells
- JavaScript tooling managed with Vite and Tailwind via `package.json`

## Development
1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Install JavaScript dependencies:
   ```bash
   npm install
   ```
3. Copy `.env.example` to `.env` and configure your environment.
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
   php artisan asset:sync --check-python --run-python --import-csv --continue --chk-date=$(date +%F)
   ```
   Use `--eod` to auto-enable those options with today's date and to skip prompts. The command will also avoid running the Python downloader if Yahoo Finance reports that the latest IDX data is not yet available.

3. **Verify and repair gaps or duplicates** – scan all configured symbols and optionally resolve issues:
   ```bash
   php artisan ohlcv:check --all --resolve=missing-days
   ```
   Use `--resolve=extra-bars` to prune stray rows or `--resolve=missing-days --force-delete` when you want missing trading days removed from the calendar after attempted recovery.

## API
A simple health check endpoint is available at `GET /api/ping`, which responds with `{ "ok": true }`

## Asset Metrics
The API includes a service for computing basic metrics on asset price data, such as the
average closing price and total traded volume. Metrics can be accessed through the
`AssetMetrics` service or via the endpoint:

```
GET /api/v1/assets/{asset}/metrics
```

Example usage within code:

```php
use App\Models\Asset;
use App\Services\AssetMetrics;

$asset = Asset::first();
$metrics = (new AssetMetrics())->forAsset($asset);
```

## Backtesting
The `asset:backtest` Artisan command executes trading strategies over historical price data.

Available strategies include:

- BreakoutAtr
- DonchianBreakout
- RocMomentum
- MovingAverageCrossover
- RsiReversal
- SupportResistanceBreakout

Trailing stops can be enabled with the `--trailing` option.  
Syntax: `--trailing=<type>:<value>[@strategy1,strategy2,...]`

- Apply a 10% trailing stop to every strategy:
  ```bash
  php artisan asset:backtest --sym=AAA --strategy=DonchianBreakout --trailing=percent:0.10
  ```
- Apply a 2×ATR trailing stop only to MovingAverageCrossover and RsiReversal:
  ```bash
  php artisan asset:backtest --sym=AAA --compare --strategies=MovingAverageCrossover,RsiReversal --trailing=atr:2@MovingAverageCrossover,RsiReversal
  ```

If `--trailing` is omitted, strategies run without a trailing stop.

## Repository Layout
```
apps/
  api/          Laravel API application
```

## Contributing
Pull requests are welcome. Please ensure tests pass before submitting your contribution.


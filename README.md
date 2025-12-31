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
   php artisan asset:sync --eod
   ```
   This commands to auto-enable all required options with today's date and to skip prompts. The command will retrieve price data from Stockbit (Bearer token prompt required) and make Python downloader for Yahoo Finance as the backup.

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

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

## Repository Layout
```
apps/
  api/          Laravel API application
```

## Contributing
Pull requests are welcome. Please ensure tests pass before submitting your contribution.


<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\BackupStatusController;
use App\Http\Controllers\Api\V1\BrokerSummaryController;
use App\Http\Controllers\Api\V1\BrokerSummaryWindowController;
use App\Http\Controllers\Api\V1\CashMovementController;
use App\Http\Controllers\Api\V1\ExecutionCandidateController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\PortfolioImportController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ScheduledTaskController;
use App\Http\Controllers\Api\V1\ScraperRequestController;
use App\Http\Controllers\Api\V1\StockbitTokenController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\StrategyWatchlistController;
use App\Http\Controllers\Api\V1\TradingDayController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
    Route::get('backup-status', [BackupStatusController::class, 'index'])
        ->name('backup-status.index');

    Route::post('backup-status/mirror-push', [BackupStatusController::class, 'mirrorPush'])
        ->name('backup-status.mirror-push');

    // The forensic file-by-file comparison, kept off the normal page load:
    // its cost grows with every archived broker-summary JSON, and the
    // readiness question the dashboard opens with does not need it.
    Route::get('backup-status/audit', [BackupStatusController::class, 'audit'])
        ->name('backup-status.audit');

    // Reconciliation: the list reads only the manifest, and a document is
    // opened when the reader asks for one asset.
    Route::get('reconciliation', [ReconciliationController::class, 'index'])
        ->name('reconciliation.index');

    Route::get('reconciliation/{symbol}', [ReconciliationController::class, 'show'])
        ->name('reconciliation.show');

    // Assets
    Route::get('assets/{asset}/latest-price', [AssetController::class, 'latestPrice'])
        ->whereNumber('asset')
        ->name('assets.latest-price');

    Route::get('assets/{symbol}/latest-price', [AssetController::class, 'latestPriceBySymbol'])
        ->whereAlphaNumeric('symbol')
        ->name('assets.latest-price.by-symbol');

    Route::get('assets/{asset}/atr', [AssetController::class, 'atr'])
        ->whereNumber('asset')
        ->name('assets.atr');

    Route::get('assets/{symbol}/atr', [AssetController::class, 'atrBySymbol'])
        ->whereAlphaNumeric('symbol')
        ->name('assets.atr.by-symbol');

    Route::get('assets/metrics', [AssetController::class, 'metricsIndex'])
        ->name('assets.metrics');

    Route::post('assets/metrics/update', [AssetController::class, 'updateMetrics'])
        ->name('assets.metrics.update');

    Route::get('assets/{asset}/metrics', [AssetController::class, 'metricForAsset'])
        ->whereNumber('asset')
        ->name('assets.metrics.show');

    Route::post('assets/sync-settings', [AssetController::class, 'updateSyncSettings'])
        ->name('assets.sync-settings');

    Route::apiResource('assets', AssetController::class);

    // Broker summary
    //
    // The legacy endpoint reads broksums, whose single `date` column cannot
    // express a range. It is kept for existing callers and is only meaningful
    // for single-day summaries.
    Route::get('broker-summaries', [BrokerSummaryController::class, 'index'])
        ->name('broker-summaries.index');

    // Canonical, range-aware. A Stockbit response is one aggregate over
    // from..to, so the window is the unit rather than a fabricated day.
    Route::get('broker-summary/windows', [BrokerSummaryWindowController::class, 'index'])
        ->name('broker-summary.windows.index');

    Route::get('broker-summary/windows/{window}', [BrokerSummaryWindowController::class, 'show'])
        ->whereNumber('window')
        ->name('broker-summary.windows.show');

    Route::get('broker-summary/entries', [BrokerSummaryWindowController::class, 'entries'])
        ->name('broker-summary.entries.index');

    // Backtest
    Route::get('backtest', [BacktestController::class, 'run']);

    // Scraper requests history
    Route::get('scraper-requests', [ScraperRequestController::class, 'index']);
    Route::post('scraper-requests', [ScraperRequestController::class, 'store']);
    Route::delete('scraper-requests/{scraperRequest}', [ScraperRequestController::class, 'destroy'])
        ->whereNumber('scraperRequest');

    // Trading days overview
    Route::get('trading-days', [TradingDayController::class, 'index']);

    // Automation: the database-managed scheduler.
    //
    // A scheduled task stores an allowlisted Artisan command name plus a
    // structured parameter map; nothing here accepts or executes a shell
    // string. See config/automation.php for the allowlist.
    Route::get('automation/status', [AutomationController::class, 'status'])
        ->name('automation.status');

    Route::get('automation/alerts', [AutomationController::class, 'alerts'])
        ->name('automation.alerts');

    Route::delete('automation/alerts/{alert}', [AutomationController::class, 'dismissAlert'])
        ->whereNumber('alert')
        ->name('automation.alerts.dismiss');

    Route::get('automation/runs', [AutomationController::class, 'runs'])
        ->name('automation.runs');

    // The token is written here and never read back. GET reports status only:
    // configured, source, fingerprint and expiry, never the bearer.
    Route::get('automation/stockbit-token', [StockbitTokenController::class, 'show'])
        ->name('automation.stockbit-token.show');

    Route::put('automation/stockbit-token', [StockbitTokenController::class, 'renew'])
        ->name('automation.stockbit-token.renew');

    Route::delete('automation/stockbit-token', [StockbitTokenController::class, 'destroy'])
        ->name('automation.stockbit-token.destroy');

    Route::post('scheduled-tasks/{scheduledTask}/toggle', [ScheduledTaskController::class, 'toggle'])
        ->whereNumber('scheduledTask')
        ->name('scheduled-tasks.toggle');

    Route::post('scheduled-tasks/{scheduledTask}/run', [ScheduledTaskController::class, 'run'])
        ->whereNumber('scheduledTask')
        ->name('scheduled-tasks.run');

    Route::get('scheduled-tasks/{scheduledTask}/runs', [ScheduledTaskController::class, 'runs'])
        ->whereNumber('scheduledTask')
        ->name('scheduled-tasks.runs');

    Route::apiResource('scheduled-tasks', ScheduledTaskController::class)
        ->whereNumber('scheduled_task');

    // The execution workspace's single composed endpoint. Structural
    // technicals, watchlist scores, features, broker windows and (optionally)
    // portfolio holdings are assembled server-side so the page makes one
    // request rather than one per row.
    Route::get('execution/candidates', [ExecutionCandidateController::class, 'index'])
        ->name('execution.candidates');

    // Strategy watchlist (read-only; produced by strategy:rank-watchlist).
    // Superseded by execution/candidates, which serves the same underlying
    // scores with the plan and status attached; kept for existing consumers.
    Route::get('strategy/watchlist', [StrategyWatchlistController::class, 'index'])
        ->name('strategy.watchlist.index');

    // Rule-builder strategies. The schema route is declared before the
    // apiResource so "strategies/schema" is not swallowed by "strategies/{id}".
    Route::get('strategies/schema', [StrategyController::class, 'schema'])
        ->name('strategies.schema');

    Route::post('strategies/{strategy}/copy', [StrategyController::class, 'copy'])
        ->whereNumber('strategy')
        ->name('strategies.copy');

    Route::post('strategies/{strategy}/run', [StrategyController::class, 'run'])
        ->whereNumber('strategy')
        ->name('strategies.run');

    Route::get('strategies/{strategy}/runs', [StrategyController::class, 'runs'])
        ->whereNumber('strategy')
        ->name('strategies.runs');

    Route::get('strategies/{strategy}/runs/{run}', [StrategyController::class, 'runMatches'])
        ->whereNumber('strategy')
        ->whereNumber('run')
        ->name('strategies.runs.matches');

    Route::apiResource('strategies', StrategyController::class)
        ->whereNumber('strategy');

    // Portfolios & positions
    Route::get('portfolios/{portfolio}/summary', [PortfolioController::class, 'summary'])
        ->whereNumber('portfolio')
        ->name('portfolios.summary');
    Route::get('portfolios/{portfolio}/holdings', [PortfolioController::class, 'holdings'])
        ->whereNumber('portfolio')
        ->name('portfolios.holdings');
    Route::get('portfolios/{portfolio}/allocations', [PortfolioController::class, 'allocations'])
        ->whereNumber('portfolio')
        ->name('portfolios.allocations');

    // Stockbit JSON import. Preview writes nothing; the commit re-runs the
    // same server-side analysis rather than trusting the previewed rows.
    // Declared before the apiResource so neither path is swallowed by
    // "portfolios/{portfolio}".
    Route::post('portfolios/{portfolio}/imports/stockbit/preview', [PortfolioImportController::class, 'preview'])
        ->whereNumber('portfolio')
        ->name('portfolios.imports.stockbit.preview');
    Route::post('portfolios/{portfolio}/imports/stockbit', [PortfolioImportController::class, 'store'])
        ->whereNumber('portfolio')
        ->name('portfolios.imports.stockbit.store');

    Route::apiResource('portfolios', PortfolioController::class);
    Route::get('portfolios/{portfolio}/positions', [PositionController::class, 'index'])
        ->name('portfolios.positions.index');
    Route::post('portfolios/{portfolio}/positions', [PositionController::class, 'store'])
        ->name('portfolios.positions.store');
    Route::get('portfolios/{portfolio}/positions/{position}', [PositionController::class, 'show'])
        ->name('portfolios.positions.show');
    Route::put('portfolios/{portfolio}/positions/{position}', [PositionController::class, 'update'])
        ->name('portfolios.positions.update');
    Route::delete('portfolios/{portfolio}/positions/{position}', [PositionController::class, 'destroy'])
        ->name('portfolios.positions.destroy');

    // Cash movements
    Route::get('portfolios/{portfolio}/cash-movements', [CashMovementController::class, 'index'])
        ->whereNumber('portfolio')
        ->name('portfolios.cash-movements.index');
    Route::post('portfolios/{portfolio}/cash-movements', [CashMovementController::class, 'store'])
        ->whereNumber('portfolio')
        ->name('portfolios.cash-movements.store');
    Route::delete('portfolios/{portfolio}/cash-movements/{cashMovement}', [CashMovementController::class, 'destroy'])
        ->whereNumber('portfolio')
        ->whereNumber('cashMovement')
        ->name('portfolios.cash-movements.destroy');
});

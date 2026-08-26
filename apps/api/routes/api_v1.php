<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\BackupStatusController;
use App\Http\Controllers\Api\V1\BrokerSummaryController;
use App\Http\Controllers\Api\V1\CashMovementController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ScraperRequestController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\StrategyWatchlistController;
use App\Http\Controllers\Api\V1\TradingDayController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
    Route::get('backup-status', [BackupStatusController::class, 'index'])
        ->name('backup-status.index');

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
    Route::get('broker-summaries', [BrokerSummaryController::class, 'index'])
        ->name('broker-summaries.index');

    // Backtest
    Route::get('backtest', [BacktestController::class, 'run']);

    // Scraper requests history
    Route::get('scraper-requests', [ScraperRequestController::class, 'index']);
    Route::post('scraper-requests', [ScraperRequestController::class, 'store']);
    Route::delete('scraper-requests/{scraperRequest}', [ScraperRequestController::class, 'destroy'])
        ->whereNumber('scraperRequest');

    // Trading days overview
    Route::get('trading-days', [TradingDayController::class, 'index']);

    // Strategy watchlist (read-only; produced by strategy:rank-watchlist)
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

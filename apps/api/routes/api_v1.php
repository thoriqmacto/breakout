<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\BrokerSummaryController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ScraperRequestController;
use App\Http\Controllers\Api\V1\TradingDayController;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
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

    // Portfolios & positions
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
});

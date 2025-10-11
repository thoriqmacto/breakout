<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\ScraperRequestController;

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

    Route::apiResource('assets', AssetController::class);

    // Backtest
    Route::get('backtest', [BacktestController::class, 'run']);

    // Scraper requests history
    Route::get('scraper-requests', [ScraperRequestController::class, 'index']);
    Route::post('scraper-requests', [ScraperRequestController::class, 'store']);
});

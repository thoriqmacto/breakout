<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
    // Assets
    Route::get('assets/{asset}/latest-price', [AssetController::class, 'latestPrice'])
        ->whereNumber('asset')
        ->name('assets.latest-price');

    Route::get('assets/{symbol}/latest-price', [AssetController::class, 'latestPriceBySymbol'])
        ->whereAlphaNumeric('symbol')
        ->name('assets.latest-price.by-symbol');

    Route::apiResource('assets', AssetController::class);

    // Backtest
    Route::get('backtest', [BacktestController::class, 'run']);
});

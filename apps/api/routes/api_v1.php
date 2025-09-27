<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
    Route::get('assets/latest-prices', [AssetController::class, 'latestPrices']);
    Route::get('assets/{asset}/latest-price', [AssetController::class, 'latestPrice']);
    Route::get('assets/{asset}/metrics', [AssetController::class, 'metrics']);
    Route::get('backtest', [BacktestController::class, 'run']);
    // Auto-register all standard REST API endpoints for templates:
    Route::apiResource('assets', AssetController::class);
});

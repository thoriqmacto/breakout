<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BacktestController;

Route::prefix('v1')->middleware(['auth:sanctum,jwt'])->group(function () {
    // Assets
    Route::apiResource('assets', AssetController::class);
    Route::get('assets/{asset}/latest-price', [AssetController::class, 'latestPrice'])
        ->name('assets.latest-price');

    // Backtest
    Route::get('backtest', [BacktestController::class, 'run']);
});

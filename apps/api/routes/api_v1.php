<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AssetController;

// When you add auth later, just add ->middleware('auth:sanctum') to this group.
Route::prefix('v1')->group(function () {
    Route::get('assets/latest-prices', [AssetController::class, 'latestPrices']);
    Route::get('assets/{asset}/latest-price', [AssetController::class, 'latestPrice']);
    // Auto-register all standard REST API endpoints for templates:
    Route::apiResource('assets', AssetController::class);
});

<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Load versioned routes (v1), for temporary only without using auth
require __DIR__.'/api_v1.php';

// generic/unversioned routes
Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware(['auth:jwt'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

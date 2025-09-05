<?php
use Illuminate\Support\Facades\Route;

// Load versioned routes (v1), for temporary only without using auth
require __DIR__ . '/api_v1.php';

// generic/unversioned routes
Route::get('/ping', fn() => response()->json(['ok' => true]));

//Route::post('/login',[AuthController::class,'login']);
//Route::middleware('auth:sanctum')->post('/logout',[AuthController::class,'logout']);
//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});

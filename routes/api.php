<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Order-booker mobile sync; only answers on the cloud copy (MOBILE_SYNC_ROLE=cloud), see config/mobile_sync.php
Route::prefix('mobile')->group(function () {
    Route::post('login', [\App\Http\Controllers\Api\MobileController::class, 'login'])->middleware(['mobile.sync:login', 'throttle:10,1']);
    Route::middleware('mobile.sync:mobile')->group(function () {
        Route::post('logout', [\App\Http\Controllers\Api\MobileController::class, 'logout']);
        Route::get('sync', [\App\Http\Controllers\Api\MobileController::class, 'sync']);
        Route::post('upload', [\App\Http\Controllers\Api\MobileController::class, 'upload']);
        Route::post('whatsapp', [\App\Http\Controllers\Api\MobileController::class, 'whatsapp']);
    });
});

// Local PC -> cloud; the full database copy sends many requests in a row, so no per-minute limit (key-guarded).
Route::prefix('sync')->middleware('mobile.sync:sync')->withoutMiddleware('throttle:api')->group(function () {
    Route::post('push', [\App\Http\Controllers\Api\MobileSyncController::class, 'push']);
    Route::get('inbox', [\App\Http\Controllers\Api\MobileSyncController::class, 'inbox']);
    Route::post('ack', [\App\Http\Controllers\Api\MobileSyncController::class, 'ack']);
    Route::get('file', [\App\Http\Controllers\Api\MobileSyncController::class, 'file']);
    Route::post('mirror/sql', [\App\Http\Controllers\Api\MobileSyncController::class, 'mirrorSql']);
});

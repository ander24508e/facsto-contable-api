<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', CurrentUserController::class);
    Route::patch('/me', [AccountController::class, 'updateProfile']);
    Route::put('/me/password', [AccountController::class, 'changePassword'])->middleware('throttle:6,1');
    Route::get('/me/photo', [AccountController::class, 'photo']);
    Route::post('/me/photo', [AccountController::class, 'updatePhoto'])->middleware('throttle:10,1');
    Route::delete('/me/photo', [AccountController::class, 'deletePhoto']);
});

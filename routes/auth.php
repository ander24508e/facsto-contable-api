<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::prefix('/api/v1/auth')->group(function (): void {
        Route::post('/login', LoginController::class)->middleware('throttle:10,1');
        Route::post('/logout', LogoutController::class)->middleware('auth');
    });
});

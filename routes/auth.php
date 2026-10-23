<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('/register', RegisterController::class)->middleware('throttle:5,1');
    Route::post('/login', LoginController::class)->middleware('throttle:10,1');
    Route::post('/logout', LogoutController::class)->middleware('auth');
});

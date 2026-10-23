<?php

use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    Route::post('/customers/{customer}/activate', [CustomerController::class, 'activate']);
    Route::post('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate']);
});

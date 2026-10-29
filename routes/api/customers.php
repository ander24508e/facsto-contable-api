<?php

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerLookupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/lookup', CustomerLookupController::class);
    Route::post('/customers/final-consumer', [CustomerController::class, 'finalConsumer']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::get('/customers/{customer}/fiscal-summary', [CustomerController::class, 'fiscalSummary']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    Route::post('/customers/{customer}/activate', [CustomerController::class, 'activate']);
    Route::post('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate']);
    Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge']);
});

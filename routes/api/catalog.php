<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\FiscalPaymentMethodController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\TaxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::get('/items/{item}', [ItemController::class, 'show']);
    Route::patch('/items/{item}', [ItemController::class, 'update']);
    Route::post('/items/{item}/activate', [ItemController::class, 'activate']);
    Route::post('/items/{item}/deactivate', [ItemController::class, 'deactivate']);
    Route::put('/items/{item}/taxes', [ItemController::class, 'syncTaxes']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::patch('/categories/{category}', [CategoryController::class, 'update']);
    Route::post('/categories/{category}/activate', [CategoryController::class, 'activate']);
    Route::post('/categories/{category}/deactivate', [CategoryController::class, 'deactivate']);
    Route::get('/taxes', [TaxController::class, 'index']);
    Route::get('/fiscal-payment-methods', [FiscalPaymentMethodController::class, 'index']);
});

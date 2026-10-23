<?php

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
    Route::get('/taxes', [TaxController::class, 'index']);
});

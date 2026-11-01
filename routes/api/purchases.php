<?php

use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

// Proveedores and Inversiones (the purchases of the business).
Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::post('/suppliers/{supplier}/activate', [SupplierController::class, 'activate']);
    Route::post('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate']);
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
});

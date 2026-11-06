<?php

use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RetentionController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

// Proveedores, Inversiones (the purchases of the business) and their retentions.
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
    // Retenciones (codDoc 07): issued on a purchase; XML, RIDE and retries live under /invoices/{document}.
    Route::post('/purchases/{purchase}/retention', [RetentionController::class, 'store']);
    Route::get('/withholding-codes', [RetentionController::class, 'codes']);
    Route::get('/retentions/eligibility', [RetentionController::class, 'eligibility']);
    Route::get('/retentions', [RetentionController::class, 'index']);
    Route::get('/retentions/{retention}', [RetentionController::class, 'show']);
});

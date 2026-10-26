<?php

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceProcessingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/sales/{sale}/invoice', [InvoiceController::class, 'fromSale']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/xml', [InvoiceProcessingController::class, 'buildXml']);
    Route::post('/invoices/{invoice}/sign', [InvoiceProcessingController::class, 'sign']);
});

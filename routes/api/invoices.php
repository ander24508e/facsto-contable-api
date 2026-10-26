<?php

use App\Http\Controllers\Api\V1\InvoiceArtifactController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceProcessingController;
use App\Http\Controllers\Api\V1\InvoiceSriController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    // Único comando de negocio: F04 → F05 → cola F06, reanudable desde el estado actual.
    Route::post('/sales/{sale}/issue', [InvoiceController::class, 'issue']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/sri-status', [InvoiceSriController::class, 'status']);
    Route::post('/invoices/{invoice}/retry-sri', [InvoiceSriController::class, 'retry']);
    Route::get('/invoices/{invoice}/artifacts', [InvoiceArtifactController::class, 'index']);
    Route::get('/invoices/{invoice}/artifacts/{type}', [InvoiceArtifactController::class, 'download'])
        ->where('type', '[A-Za-z_-]+');

    // Pasos técnicos sueltos: sólo desarrollo y pruebas, nunca en producción.
    if (! app()->isProduction()) {
        Route::post('/invoices/{invoice}/xml', [InvoiceProcessingController::class, 'buildXml']);
        Route::post('/invoices/{invoice}/sign', [InvoiceProcessingController::class, 'sign']);
    }
});

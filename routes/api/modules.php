<?php

use App\Http\Controllers\Api\V1\ApiClientController;
use App\Http\Controllers\Api\V1\ModuleInvoiceController;
use Illuminate\Support\Facades\Route;

// Módulos (Taller, Lavadora…) con su token "Bearer fct_…": facturan, consultan y descargan el RIDE.
Route::middleware(['api.client', 'throttle:120,1'])->group(function (): void {
    Route::post('/invoices', [ModuleInvoiceController::class, 'store']);
    Route::get('/module/invoices/{invoice}', [ModuleInvoiceController::class, 'show'])->whereUuid('invoice');
    Route::get('/module/invoices/{invoice}/ride', [ModuleInvoiceController::class, 'ride'])->whereUuid('invoice');
});

// Configuración → Integraciones (sesión de un OWNER/ADMIN).
Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/api-clients', [ApiClientController::class, 'index']);
    Route::post('/api-clients', [ApiClientController::class, 'store']);
    Route::patch('/api-clients/{apiClient}', [ApiClientController::class, 'update'])->whereUuid('apiClient');
    Route::delete('/api-clients/{apiClient}', [ApiClientController::class, 'destroy'])->whereUuid('apiClient');
});

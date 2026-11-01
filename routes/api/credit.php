<?php

use App\Http\Controllers\Api\V1\PayableController;
use App\Http\Controllers\Api\V1\ReceivableController;
use Illuminate\Support\Facades\Route;

// Cuentas por cobrar (sales on CREDITO) and cuentas por pagar (purchases on CREDITO).
Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/receivables', [ReceivableController::class, 'index']);
    Route::get('/receivables/{sale}', [ReceivableController::class, 'show']);
    Route::post('/receivables/{sale}/payments', [ReceivableController::class, 'collect']);
    Route::get('/payables', [PayableController::class, 'index']);
    Route::get('/payables/{purchase}', [PayableController::class, 'show']);
    Route::post('/payables/{purchase}/payments', [PayableController::class, 'pay']);
});

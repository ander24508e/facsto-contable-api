<?php

use App\Http\Controllers\Api\V1\CashSessionController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\FiscalDashboardController;
use App\Http\Controllers\Api\V1\ProformaController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleQuoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::get('/dashboard/summary', DashboardSummaryController::class);
    Route::get('/dashboard/fiscal-status', [FiscalDashboardController::class, 'status']);
    Route::get('/dashboard/trend', [FiscalDashboardController::class, 'trend']);
    Route::get('/dashboard/actions', [FiscalDashboardController::class, 'actions']);
    Route::get('/dashboard/tax-calendar', [FiscalDashboardController::class, 'taxCalendar']);
    Route::get('/reports/tax-summary', [FiscalDashboardController::class, 'taxSummary']);
    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::post('/sales/quote', SaleQuoteController::class);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::patch('/sales/{sale}', [SaleController::class, 'update']);
    Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel']);
    Route::get('/proformas', [ProformaController::class, 'index']);
    Route::post('/proformas', [ProformaController::class, 'store']);
    Route::get('/proformas/{proforma}', [ProformaController::class, 'show']);
    Route::patch('/proformas/{proforma}', [ProformaController::class, 'update']);
    Route::delete('/proformas/{proforma}', [ProformaController::class, 'destroy']);
    Route::get('/cash-sessions', [CashSessionController::class, 'index']);
    Route::get('/cash-sessions/denominations', [CashSessionController::class, 'denominations']);
    Route::post('/cash-sessions', [CashSessionController::class, 'store']);
    Route::get('/cash-sessions/{session}', [CashSessionController::class, 'show']);
    Route::post('/cash-sessions/{session}/movements', [CashSessionController::class, 'storeMovement']);
    Route::post('/cash-sessions/{session}/close', [CashSessionController::class, 'close']);
});

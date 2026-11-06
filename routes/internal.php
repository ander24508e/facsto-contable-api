<?php

use App\Http\Controllers\Internal\V1\PlatformEntitlementController;
use Illuminate\Support\Facades\Route;

/*
 * Internal API (prefijo /internal/v1), sólo para Condore Platform y firmada con CondoreSignature.
 * Platform es la única fuente de derechos de módulos; FACSTO guarda la última copia y sigue
 * funcionando aunque Platform no responda.
 */
Route::get('/platform/companies/{company}/entitlements', [PlatformEntitlementController::class, 'show'])->whereUuid('company');
Route::put('/platform/companies/{company}/entitlements', [PlatformEntitlementController::class, 'update'])->whereUuid('company')->middleware('throttle:120,1');

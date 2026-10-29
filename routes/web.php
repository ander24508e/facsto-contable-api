<?php

use App\Http\Controllers\PublicRideController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/health', function () {
    $services = [
        'api' => 'ok',
        'postgresql' => 'ok',
        'redis' => 'ok',
        'private_storage' => 'ok',
    ];

    try {
        DB::select('select 1');
    } catch (Throwable) {
        $services['postgresql'] = 'unavailable';
    }

    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $services['redis'] = 'unavailable';
    }

    try {
        Storage::disk('private')->exists('.health-check');
    } catch (Throwable) {
        $services['private_storage'] = 'unavailable';
    }

    $isHealthy = collect($services)->every(fn (string $status) => $status === 'ok');

    return response()->json([
        'status' => $isHealthy ? 'ok' : 'degraded',
        'services' => $services,
    ], $isHealthy ? 200 : 503);
});

// Enlace firmado y temporal al RIDE de una factura autorizada (POST /api/v1/invoices/{invoice}/share-link).
Route::get('/public/ride/{invoice}', PublicRideController::class)
    ->middleware(['signed', 'throttle:60,1'])
    ->name('public.ride');

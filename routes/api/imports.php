<?php

use App\Http\Controllers\Api\V1\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.context'])->group(function (): void {
    Route::post('/imports/preview', [ImportController::class, 'preview']);
    Route::post('/imports/{import}/commit', [ImportController::class, 'commit'])->whereUuid('import');
});

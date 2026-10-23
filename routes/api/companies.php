<?php

use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyMemberController;
use App\Http\Controllers\Api\V1\EmissionPointController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\Setup\CertificateSetupController;
use App\Http\Controllers\Api\V1\Setup\EmissionSetupController;
use App\Http\Controllers\Api\V1\Setup\IssuerSetupController;
use App\Http\Controllers\Api\V1\Setup\SetupDeferralController;
use App\Http\Controllers\Api\V1\Setup\SetupStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::patch('/companies/{company}', [CompanyController::class, 'update']);
    Route::post('/companies/{company}/members', [CompanyMemberController::class, 'store']);
    Route::delete('/companies/{company}/members/{userPublicId}', [CompanyMemberController::class, 'destroy']);
    Route::post('/companies/{company}/members/{userPublicId}/reactivate', [CompanyMemberController::class, 'reactivate']);
    Route::patch('/companies/{company}/members/{userPublicId}/role', [CompanyMemberController::class, 'updateRole']);

    Route::middleware('company.context')->group(function (): void {
        Route::get('/setup/status', SetupStatusController::class);
        Route::post('/setup/defer', [SetupDeferralController::class, 'defer']);
        Route::post('/setup/resume', [SetupDeferralController::class, 'resume']);
        Route::put('/setup/issuer', [IssuerSetupController::class, 'update']);
        Route::put('/setup/emission', [EmissionSetupController::class, 'update']);
        Route::post('/setup/certificate', [CertificateSetupController::class, 'store'])->middleware('throttle:5,1');
        Route::delete('/setup/certificate/{certificate}', [CertificateSetupController::class, 'destroy']);
        Route::get('/establishments', [EstablishmentController::class, 'index']);
        Route::post('/establishments', [EstablishmentController::class, 'store']);
        Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);
        Route::get('/emission-points', [EmissionPointController::class, 'index']);
        Route::post('/emission-points', [EmissionPointController::class, 'store']);
        Route::patch('/emission-points/{emissionPoint}', [EmissionPointController::class, 'update']);
    });
});

<?php

use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyMemberController;
use App\Http\Controllers\Api\V1\EmissionPointController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\FiscalProfileController;
use App\Http\Controllers\Api\V1\Setup\CertificateSetupController;
use App\Http\Controllers\Api\V1\Setup\EmissionSetupController;
use App\Http\Controllers\Api\V1\Setup\IssuerSetupController;
use App\Http\Controllers\Api\V1\Setup\SetupDeferralController;
use App\Http\Controllers\Api\V1\Setup\SetupStatusController;
use Illuminate\Support\Facades\Route;

// Las empresas las crea Condore Platform (hoy el comando facsto:empresa:crear); aquí sólo
// se opera sobre la empresa del usuario autenticado, resuelta en el servidor.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::middleware('company.context')->group(function (): void {
        Route::get('/company', [CompanyController::class, 'show']);
        Route::patch('/company', [CompanyController::class, 'update']);
        Route::get('/company/logo', [CompanyController::class, 'logo']);
        Route::post('/company/logo', [CompanyController::class, 'updateLogo'])->middleware('throttle:10,1');
        Route::delete('/company/logo', [CompanyController::class, 'deleteLogo']);
        Route::post('/company/members', [CompanyMemberController::class, 'store']);
        Route::delete('/company/members/{userPublicId}', [CompanyMemberController::class, 'destroy']);
        Route::post('/company/members/{userPublicId}/reactivate', [CompanyMemberController::class, 'reactivate']);
        Route::patch('/company/members/{userPublicId}/role', [CompanyMemberController::class, 'updateRole']);
        Route::get('/fiscal-profile', [FiscalProfileController::class, 'show']);
        Route::patch('/fiscal-profile', [FiscalProfileController::class, 'update']);
        Route::get('/setup/status', SetupStatusController::class);
        Route::post('/setup/defer', [SetupDeferralController::class, 'defer']);
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

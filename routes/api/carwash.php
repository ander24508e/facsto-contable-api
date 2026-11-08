<?php

use App\Http\Controllers\Api\V1\Carwash\AreaTypeController;
use App\Http\Controllers\Api\V1\Carwash\OperationsController;
use App\Http\Controllers\Api\V1\Carwash\ServiceController;
use App\Http\Controllers\Api\V1\Carwash\VehicleCatalogController;
use App\Http\Controllers\Api\V1\Carwash\VehicleController;
use App\Http\Controllers\Api\V1\Carwash\WasherController;
use App\Http\Controllers\Api\V1\Carwash\WorkAreaController;
use App\Http\Controllers\Api\V1\Carwash\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Módulo Lavadora: /api/v1/carwash. ModulesServiceProvider ya aplica auth:sanctum, company.context y
 * module:carwash (sin derecho → 403; en solo lectura no se escribe). Los permisos son los del bloque Lavadora de los
 * roles de la empresa: carwash.view, carwash.operate, carwash.manage; carwash.own_work ve sólo sus órdenes asignadas.
 */
// «Ver su propio trabajo»: la lista y el detalle de órdenes, filtrados a las suyas (WorkOrderController).
Route::middleware('can:carwash.orders')->group(function (): void {
    Route::get('/work-orders', [WorkOrderController::class, 'index']);
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->whereUuid('workOrder');
});

Route::middleware('can:carwash.view')->group(function (): void {
    Route::get('/dashboard', [OperationsController::class, 'dashboard']);
    Route::get('/agenda', [OperationsController::class, 'agenda']);
    Route::get('/settings', [OperationsController::class, 'settings']);
    Route::get('/vehicle-types', [VehicleCatalogController::class, 'types']);
    Route::get('/vehicle-brands', [VehicleCatalogController::class, 'brands']);
    Route::get('/vehicle-specs', [VehicleCatalogController::class, 'specs']);
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/vehicles/lookup', [VehicleController::class, 'lookup']);
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->whereUuid('vehicle');
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{service}', [ServiceController::class, 'show'])->whereUuid('service');
    Route::get('/categories', [OperationsController::class, 'categories']);
    Route::get('/washers', [WasherController::class, 'index']);
    Route::get('/area-types', [AreaTypeController::class, 'index']);
    Route::get('/work-areas', [WorkAreaController::class, 'index']);
    Route::get('/work-orders/next-code', [WorkOrderController::class, 'nextCode']);
});

Route::middleware('can:carwash.operate')->group(function (): void {
    Route::post('/vehicles', [VehicleController::class, 'store']);
    // Servicios and the vehicle form create a missing brand or specification on the spot («+» next to the field).
    Route::post('/vehicle-brands', [VehicleCatalogController::class, 'storeBrand']);
    Route::post('/vehicle-specs', [VehicleCatalogController::class, 'storeSpec']);
    Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->whereUuid('vehicle');
    Route::post('/work-orders', [WorkOrderController::class, 'store']);
    Route::patch('/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->whereUuid('workOrder');
    Route::post('/work-orders/{workOrder}/transitions', [WorkOrderController::class, 'transition'])->whereUuid('workOrder');
    Route::post('/work-orders/{workOrder}/charge', [WorkOrderController::class, 'charge'])->whereUuid('workOrder')->middleware('throttle:30,1');
});

Route::middleware('can:carwash.manage')->group(function (): void {
    Route::put('/settings', [OperationsController::class, 'updateSettings']);
    Route::post('/vehicle-types', [VehicleCatalogController::class, 'storeType']);
    Route::patch('/vehicle-types/{type}', [VehicleCatalogController::class, 'updateType'])->whereUuid('type');
    Route::patch('/vehicle-brands/{brand}', [VehicleCatalogController::class, 'updateBrand'])->whereUuid('brand');
    Route::patch('/vehicle-specs/{spec}', [VehicleCatalogController::class, 'updateSpec'])->whereUuid('spec');
    Route::put('/categories', [OperationsController::class, 'updateCategories']);
    Route::patch('/services/{service}', [ServiceController::class, 'update'])->whereUuid('service');
    Route::put('/services/{service}/usages', [ServiceController::class, 'usages'])->whereUuid('service');
    Route::post('/services/{service}/prices', [ServiceController::class, 'storePrice'])->whereUuid('service');
    Route::patch('/service-prices/{price}', [ServiceController::class, 'updatePrice'])->whereUuid('price');
    Route::post('/area-types', [AreaTypeController::class, 'store']);
    Route::patch('/area-types/{areaType}', [AreaTypeController::class, 'update'])->whereUuid('areaType');
    Route::post('/work-areas', [WorkAreaController::class, 'store']);
    Route::patch('/work-areas/{workArea}', [WorkAreaController::class, 'update'])->whereUuid('workArea');
});

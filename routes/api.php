<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/companies.php';
    require __DIR__.'/api/customers.php';
    require __DIR__.'/api/catalog.php';
    require __DIR__.'/api/sales.php';
    require __DIR__.'/api/invoices.php';
});

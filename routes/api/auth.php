<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/me', CurrentUserController::class);

<?php

use App\Http\Controllers\Api\ApiAuthenticationController;
use App\Http\Controllers\Api\ApiUserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [ApiAuthenticationController::class, 'store'])->middleware('throttle:login');

Route::middleware('auth:api')->get('/user', ApiUserController::class);

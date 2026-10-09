<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\StaffDashboardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticationController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticationController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware('role:Admin,Manager,Cashier')->name('dashboard');
    Route::get('/staff/dashboard', StaffDashboardController::class)->middleware('role:Staff,Delivery Staff')->name('staff.dashboard');
    Route::post('/logout', [AuthenticationController::class, 'destroy'])->name('logout');
});

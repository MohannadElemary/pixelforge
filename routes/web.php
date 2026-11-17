<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/studio');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::get('/register', [AuthController::class, 'show'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/studio', [StudioController::class, 'index']);
    Route::post('/estimate', [StudioController::class, 'estimate'])->middleware('throttle:60,1');
    Route::post('/generations', [StudioController::class, 'store'])->middleware('throttle:12,1');
    Route::post('/generations/{generation}/retry', [StudioController::class, 'retry'])->middleware('throttle:6,1');
});

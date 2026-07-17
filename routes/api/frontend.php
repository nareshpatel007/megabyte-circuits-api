<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ScrapingController;
use App\Http\Controllers\OrderController;

// Public auth
Route::post('auth/callback/google', [AuthController::class, 'googleCallback']);
Route::get('demo-call', [ScrapingController::class, 'demoCall']);
Route::post('visitor/track', [\App\Http\Controllers\VisitorController::class, 'track']);
Route::post('contact', [\App\Http\Controllers\ContactController::class, 'submitContact']);

Route::middleware('verify.api.token')->group(function () {
    // PCB Orders (Public)
    Route::prefix('orders/')->group(function () {
        Route::post('submit', [OrderController::class, 'store']);
    });
});
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ScrapingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\FileUploadController;

Route::middleware('verify.api.token')->group(function () {
    // PCB Orders
    Route::prefix('orders/')->group(function () {
        Route::post('submit', [OrderController::class, 'store']);
    });

    // File Upload
    Route::post('upload', [FileUploadController::class, 'upload']);
});
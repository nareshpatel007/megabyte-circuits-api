<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\StatusController;

// Public Admin Auth
Route::post('admin/login', [AdminController::class, 'login']);

// Protected Admin Panel Endpoints
Route::middleware('verify.admin.token')->group(function () {
    Route::prefix('admin/')->group(function () {
        // Blog Management
        Route::get('blogs', [BlogController::class, 'index']);
        Route::get('blogs/{id}', [BlogController::class, 'show']);
        Route::post('blogs', [BlogController::class, 'store']);
        Route::put('blogs/{id}', [BlogController::class, 'update']);
        Route::delete('blogs/{id}', [BlogController::class, 'destroy']);
        Route::post('blogs/upload-image',[BlogController::class, 'uploadImage']);
        Route::post('blogs/generate-ai', [BlogController::class, 'generateAI']);

        // Status Management
        Route::get('statuses', [StatusController::class, 'index']);
        Route::post('statuses', [StatusController::class, 'store']);
        Route::put('statuses/{id}', [StatusController::class, 'update']);
        Route::delete('statuses/{id}', [StatusController::class, 'destroy']);
    });
});

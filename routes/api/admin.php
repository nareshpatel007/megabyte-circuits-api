<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;

// Public Admin Auth
Route::post('admin/login', [AdminController::class, 'login']);

// Protected Admin Panel Endpoints
Route::middleware('verify.admin.token')->group(function () {
    Route::prefix('admin/')->group(function () {
        Route::get('stats', [AdminController::class, 'stats']);
        Route::post('change-password', [AdminController::class, 'changePassword']);
        Route::get('users', [AdminController::class, 'users']);
        Route::post('users/{id}/credits', [AdminController::class, 'updateUserCredits']);
        Route::post('users/{id}/status', [AdminController::class, 'toggleUserStatus']);

        Route::get('tickets', [AdminController::class, 'tickets']);
        Route::post('tickets/{id}/reply', [AdminController::class, 'replyTicket']);

        Route::get('credit-packs', [AdminController::class, 'listPacks']);
        Route::post('credit-packs', [AdminController::class, 'createPack']);
        Route::put('credit-packs/{id}', [AdminController::class, 'updatePack']);
        Route::delete('credit-packs/{id}', [AdminController::class, 'deletePack']);

        Route::get('usage-logs', [AdminController::class, 'usageLogs']);
        Route::get('payments', [AdminController::class, 'payments']);
        Route::get('visitors', [AdminController::class, 'visitors']);

        // Blog Management
        Route::get('blogs', [BlogController::class, 'index']);
        Route::get('blogs/{id}', [BlogController::class, 'show']);
        Route::post('blogs', [BlogController::class, 'store']);
        Route::put('blogs/{id}', [BlogController::class, 'update']);
        Route::delete('blogs/{id}', [BlogController::class, 'destroy']);
        Route::post('blogs/upload-image',[BlogController::class, 'uploadImage']);
        Route::post('blogs/generate-ai', [BlogController::class, 'generateAI']);

        // Contact Messages Management
        Route::get('contact-messages', [ContactController::class, 'index']);
        Route::post('contact-messages', [ContactController::class, 'store']);
        Route::put('contact-messages/{id}', [ContactController::class, 'update']);
        Route::delete('contact-messages/{id}', [ContactController::class, 'destroy']);
    });
});

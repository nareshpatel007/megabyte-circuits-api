<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\OrderController;

// Public Admin Auth
Route::post('admin/login', [AdminController::class, 'login']);

// Protected Admin Panel Endpoints
Route::middleware('verify.admin.token')->group(function () {
    Route::prefix('admin/')->group(function () {
        // Dashboard Stats
        Route::get('stats', [AdminController::class, 'stats']);

        // Order Management
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::put('orders/{id}', [OrderController::class, 'update']);
        Route::get('orders/{id}/notes', [OrderController::class, 'getNotes']);
        Route::post('orders/{id}/notes', [OrderController::class, 'addNote']);
        Route::delete('orders/notes/{noteId}', [OrderController::class, 'deleteNote']);

        // User & Role Management
        Route::get('staff', [AdminController::class, 'listStaff']);
        Route::post('staff', [AdminController::class, 'createStaff']);
        Route::put('staff/{id}', [AdminController::class, 'updateStaff']);
        Route::delete('staff/{id}', [AdminController::class, 'deleteStaff']);

        Route::get('roles', [AdminController::class, 'listRoles']);
        Route::post('roles', [AdminController::class, 'createRole']);
        Route::put('roles/{id}', [AdminController::class, 'updateRole']);
        Route::delete('roles/{id}', [AdminController::class, 'deleteRole']);
        Route::get('permissions', [AdminController::class, 'listPermissions']);

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

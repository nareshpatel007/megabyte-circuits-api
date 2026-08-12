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
        Route::post('orders', [OrderController::class, 'createAdminOrder']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::put('orders/{id}', [OrderController::class, 'update']);
        Route::get('orders/{id}/logs', [OrderController::class, 'getLogs']);
        Route::get('orders/{id}/notes', [OrderController::class, 'getNotes']);
        Route::post('orders/{id}/notes', [OrderController::class, 'addNote']);
        Route::delete('orders/notes/{noteId}', [OrderController::class, 'deleteNote']);

        // User & Role Management
        Route::get('users', [AdminController::class, 'users']);
        Route::post('users', [AdminController::class, 'createUser']);
        Route::get('users/{id}', [AdminController::class, 'showUser']);
        Route::put('users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('users/{id}', [AdminController::class, 'deleteUser']);
        Route::put('users/{id}/status', [AdminController::class, 'toggleUserStatus']);
        Route::get('staff', [AdminController::class, 'listStaff']);
        Route::get('staff/{id}', [AdminController::class, 'showStaff']);
        Route::post('staff', [AdminController::class, 'createStaff']);
        Route::put('staff/{id}', [AdminController::class, 'updateStaff']);
        Route::delete('staff/{id}', [AdminController::class, 'deleteStaff']);

        Route::get('roles', [AdminController::class, 'listRoles']);
        Route::get('roles/{id}', [AdminController::class, 'showRole']);
        Route::post('roles', [AdminController::class, 'createRole']);
        Route::put('roles/{id}', [AdminController::class, 'updateRole']);
        Route::delete('roles/{id}', [AdminController::class, 'deleteRole']);
        Route::get('permissions', [AdminController::class, 'listPermissions']);
        Route::get('my-permissions', [AdminController::class, 'myPermissions']);

        // Blog Management
        Route::get('blogs', [BlogController::class, 'index']);
        Route::get('blogs/{id}', [BlogController::class, 'show']);
        Route::post('blogs', [BlogController::class, 'store']);
        Route::put('blogs/{id}', [BlogController::class, 'update']);
        Route::delete('blogs/{id}', [BlogController::class, 'destroy']);
        Route::post('blogs/upload-image',[BlogController::class, 'uploadImage']);
        Route::post('blogs/generate-ai', [BlogController::class, 'generateAI']);

        // Payment Management
        Route::get('payments', [AdminController::class, 'payments']);

        // Gerber Management
        Route::get('gerber-files', [AdminController::class, 'gerberFiles']);
        Route::delete('gerber-files/{id}', [AdminController::class, 'deleteGerberFile']);

        // Status Management
        Route::get('statuses', [StatusController::class, 'index']);
        Route::post('statuses', [StatusController::class, 'store']);
        Route::put('statuses/{id}', [StatusController::class, 'update']);
        Route::delete('statuses/{id}', [StatusController::class, 'destroy']);

        // Inventory Management
        Route::get('inventory', [\App\Http\Controllers\InventoryController::class, 'index']);
        Route::get('inventory/{id}', [\App\Http\Controllers\InventoryController::class, 'show']);
        Route::post('inventory', [\App\Http\Controllers\InventoryController::class, 'store']);
        Route::put('inventory/{id}', [\App\Http\Controllers\InventoryController::class, 'update']);
        Route::delete('inventory/{id}', [\App\Http\Controllers\InventoryController::class, 'destroy']);
        Route::post('inventory/{id}/stock', [\App\Http\Controllers\InventoryController::class, 'adjustStock']);
        Route::get('inventory/{id}/logs', [\App\Http\Controllers\InventoryController::class, 'getLogs']);

        // PCB Pricing Calculations Management
        Route::get('pcb-pricing', [\App\Http\Controllers\PcbPricingController::class, 'getPricingConfig']);
        Route::post('pcb-pricing', [\App\Http\Controllers\PcbPricingController::class, 'updatePricingConfig']);
        Route::post('pcb-pricing/reset', [\App\Http\Controllers\PcbPricingController::class, 'resetPricingConfig']);
    });
});

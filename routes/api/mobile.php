<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\MobileBootstrapController;
use App\Http\Controllers\Mobile\MobileDashboardController;
use App\Http\Controllers\Mobile\MobileOrderController;
use App\Http\Controllers\Mobile\MobileInventoryController;
use App\Http\Controllers\Mobile\MobileProfileController;
use App\Http\Controllers\Mobile\MobileNotificationController;

/*
|--------------------------------------------------------------------------
| Mobile API Routes v1
|--------------------------------------------------------------------------
*/

Route::prefix('mobile/v1')->group(function () {

    // Auth Routes
    Route::post('auth/login', [MobileAuthController::class, 'login']);

    // Protected Mobile Routes
    Route::middleware('verify.admin.token')->group(function () {
        Route::post('auth/logout', [MobileAuthController::class, 'logout']);
        Route::get('bootstrap', [MobileBootstrapController::class, 'bootstrap']);
        Route::get('dashboard', [MobileDashboardController::class, 'dashboard']);

        // Statuses
        Route::get('statuses', [MobileOrderController::class, 'getStatuses']);

        // Orders
        Route::get('orders', [MobileOrderController::class, 'index']);
        Route::get('orders/{id}', [MobileOrderController::class, '  ']);
        Route::get('orders/{id}/history', [MobileOrderController::class, 'getHistory']);
        Route::get('orders/{id}/notes', [MobileOrderController::class, 'getNotes']);
        Route::post('orders/{id}/notes', [MobileOrderController::class, 'addNote']);
        Route::put('orders/{id}/status', [MobileOrderController::class, 'updateStatus']);
        Route::put('orders/{id}/quantities', [MobileOrderController::class, 'updateQuantities']);
        Route::put('orders/{id}/film-applied', [MobileOrderController::class, 'updateFilmApplied']);

        // Inventory
        Route::get('inventory', [MobileInventoryController::class, 'index']);
        Route::get('inventory/{id}', [MobileInventoryController::class, 'show']);
        Route::post('inventory/{id}/stock', [MobileInventoryController::class, 'adjustStock']);

        // Profile
        Route::get('profile', [MobileProfileController::class, 'profile']);
        Route::put('profile', [MobileProfileController::class, 'updateProfile']);
        Route::put('profile/password', [MobileProfileController::class, 'changePassword']);

        // Notifications
        Route::get('notifications', [MobileNotificationController::class, 'index']);
        Route::post('notifications/{id}/read', [MobileNotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [MobileNotificationController::class, 'markAllAsRead']);
    });
});

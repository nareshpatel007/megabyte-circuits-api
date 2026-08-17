<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ScrapingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GoogleController;

// File Upload (Public for testing)
Route::post('upload', [FileUploadController::class, 'upload']);

// PCB Pricing Calculations (Public)
Route::get('pcb-pricing', [\App\Http\Controllers\PcbPricingController::class, 'getPricingConfig']);

// PCB Orders
Route::prefix('orders/')->group(function () {
    Route::post('submit', [OrderController::class, 'store']);
});

// DigiKey Products API (DB stored)
Route::get('digikey/products', [\App\Http\Controllers\DigiKeyProductsController::class, 'index']);
Route::get('digikey/products/{partNumber}', [\App\Http\Controllers\DigiKeyProductsController::class, 'show']);
Route::post('digikey/sync', [\App\Http\Controllers\DigiKeyProductsController::class, 'triggerSync']);


// Auth Routes
Route::prefix('auth/')->group(function () {
    Route::get('google', [GoogleController::class, 'redirect']);
    Route::get('google/callback', [GoogleController::class, 'callback']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('google-login', [AuthController::class, 'googleLogin']);
    Route::post('logout', [AuthController::class, 'logout']);
});

// Cart Routes
Route::prefix('cart/')->group(function () {
    Route::post('save', [CartController::class, 'save']);
    Route::get('get', [CartController::class, 'get']);
});

use App\Http\Controllers\DashboardController;

// Checkout Routes
Route::prefix('checkout/')->group(function () {
    Route::get('addresses', [CheckoutController::class, 'getAddresses']);
    Route::post('save-address', [CheckoutController::class, 'saveAddress']);
    Route::post('delete-address', [CheckoutController::class, 'deleteAddress']);
    Route::post('create-razorpay-order', [CheckoutController::class, 'createRazorpayOrder']);
    Route::post('verify-payment', [CheckoutController::class, 'verifyPaymentAndCreateOrders']);
});

// User Dashboard Routes
Route::prefix('dashboard/')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('sidebar-counts', [DashboardController::class, 'sidebarCounts']);
    Route::get('account', [DashboardController::class, 'accountDetails']);
    Route::post('update-gst', [DashboardController::class, 'updateGst']);
    Route::get('orders', [DashboardController::class, 'orders']);
    Route::get('order-details', [DashboardController::class, 'orderDetails']);
    Route::get('gerber-files', [DashboardController::class, 'gerberFiles']);
    Route::post('delete-gerber', [DashboardController::class, 'deleteGerberFile']);
    Route::get('payments', [DashboardController::class, 'payments']);
    Route::get('search', [DashboardController::class, 'search']);
});

// Frontend API Routes (Protected by API token)
Route::middleware('verify.api.token')->group(function () {
    
});
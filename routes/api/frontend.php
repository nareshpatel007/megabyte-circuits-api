<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ScrapingController;

// Public auth
Route::post('auth/callback/google', [AuthController::class, 'googleCallback']);
Route::get('demo-call', [ScrapingController::class, 'demoCall']);
Route::post('visitor/track', [\App\Http\Controllers\VisitorController::class, 'track']);
Route::post('contact', [\App\Http\Controllers\ContactController::class, 'submitContact']);

Route::middleware('verify.api.token')->group(function () {
    // Blogs
    Route::prefix('blogs/')->group(function () {
        Route::get('public', [\App\Http\Controllers\BlogController::class, 'publicIndex']);
        Route::get('public/{slug}', [\App\Http\Controllers\BlogController::class, 'publicShow']);
    });

    // Auth
    Route::prefix('auth/')->group(function () {
        Route::post('google-login', [AuthController::class, 'googleLogin']);
        Route::get('profile', [AuthController::class, 'profile']);
    });

    Route::prefix('account/')->group(function () {
        Route::get('usage-logs', [AuthController::class, 'usageLogs']);
        Route::get('wallet-transactions', [AuthController::class, 'walletTransactions']);
        Route::get('payments', [AuthController::class, 'paymentHistory']);
        Route::post('rotate-api-key', [AuthController::class, 'rotateApiKey']);
        Route::get('credit-packs', [AuthController::class, 'getCreditPacks']);
        Route::post('razorpay/create-order', [AuthController::class, 'createRazorpayOrder']);
        Route::post('razorpay/verify-payment', [AuthController::class, 'verifyRazorpayPayment']);
    });

    Route::prefix('support/')->group(function () {
        Route::get('tickets', [SupportController::class, 'getTickets']);
        Route::post('tickets', [SupportController::class, 'createTicket']);
    });

    // Scraping endpoints
    Route::prefix('scraping/')->group(function () {
        Route::post('single', [ScrapingController::class, 'scrapeSingle']);
        Route::post('bulk', [ScrapingController::class, 'scrapeBulk']);
        Route::get('history', [ScrapingController::class, 'getHistory']);
        Route::post('history/download', [ScrapingController::class, 'downloadHistory']);
        Route::post('download-csv', [ScrapingController::class, 'downloadCsv']);
    });
});

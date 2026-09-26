<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\OrderController;

// Public Admin Routes (No Token Required)
Route::prefix('admin')->group(function () {
    Route::post('login', [AdminController::class, 'login']);
    Route::post('auth/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'adminForgotPassword']);
    Route::post('auth/verify-password-reset-otp', [\App\Http\Controllers\PasswordResetController::class, 'adminVerifyOtp']);
    Route::post('auth/resend-password-reset-otp', [\App\Http\Controllers\PasswordResetController::class, 'adminResendOtp']);
    Route::post('auth/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'adminResetPassword']);
    Route::get('blogs/first-10', [BlogController::class, 'firstTenBlogs']);
});

// Protected Admin Panel Endpoints
Route::middleware('verify.admin.token')->group(function () {
    Route::prefix('admin')->group(function () {
        // Dashboard Stats & Analytics
        Route::get('stats', [AdminController::class, 'stats']);
        Route::get('revenue-trend', [AdminController::class, 'revenueTrend']);

        // Order Management
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'createAdminOrder']);
        Route::get('orders/import-sample', [OrderController::class, 'importSample']);
        Route::post('orders/import-preview', [OrderController::class, 'importPreview']);
        Route::post('orders/import', [OrderController::class, 'importExecute']);
        Route::post('orders/import-upload', [OrderController::class, 'uploadImport']);
        Route::post('orders/import/upload', [OrderController::class, 'uploadImportStaged']);
        Route::get('orders/import/{id}/rows', [OrderController::class, 'getStagedRows']);
        Route::put('orders/import/{id}/rows/{rowId}', [OrderController::class, 'updateStagedRowCell']);
        Route::post('orders/import/{id}/start', [OrderController::class, 'startStagedImport']);
        Route::post('orders/import/{id}/process-chunk', [OrderController::class, 'processChunk']);
        Route::get('orders/imports', [OrderController::class, 'listImports']);
        Route::get('orders/imports/{id}', [OrderController::class, 'showImport']);
        Route::post('orders/imports/{id}/retry', [OrderController::class, 'retryImport']);
        Route::post('orders/imports/{id}/cancel', [OrderController::class, 'cancelImport']);
        Route::get('orders/export-preview', [OrderController::class, 'exportPreview']);
        Route::get('orders/export', [OrderController::class, 'export']);
        Route::post('orders/{id}/reorder', [OrderController::class, 'reorder']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::put('orders/{id}', [OrderController::class, 'update']);
        Route::get('orders/{id}/logs', [OrderController::class, 'getLogs']);
        Route::get('orders/{id}/notes', [OrderController::class, 'getNotes']);
        Route::post('orders/{id}/notes', [OrderController::class, 'addNote']);
        Route::delete('orders/notes/{noteId}', [OrderController::class, 'deleteNote']);

        // Job Card Management & PDF Generation
        Route::get('orders/{id}/job-card', [\App\Http\Controllers\JobCardController::class, 'show']);
        Route::post('orders/{id}/job-card', [\App\Http\Controllers\JobCardController::class, 'save']);
        Route::match(['get', 'post'], 'orders/{id}/job-card/pdf', [\App\Http\Controllers\JobCardController::class, 'generatePdf']);
        Route::get('orders/{id}/job-card/documents', [\App\Http\Controllers\JobCardController::class, 'listDocuments']);
        Route::post('orders/{id}/job-card/documents/upload', [\App\Http\Controllers\JobCardController::class, 'uploadDocument']);
        Route::delete('orders/{id}/job-card/documents/{docId}', [\App\Http\Controllers\JobCardController::class, 'deleteDocument']);
        Route::put('orders/{id}/job-card/documents/reorder', [\App\Http\Controllers\JobCardController::class, 'reorderDocuments']);
        Route::get('orders/{id}/job-card/documents/{docId}/file', [\App\Http\Controllers\JobCardController::class, 'streamDocumentFile']);
        Route::post('orders/{id}/job-card/combined-pdf', [\App\Http\Controllers\JobCardController::class, 'generateCombinedPdf']);


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
        Route::get('blogs/stats', [BlogController::class, 'adminStats']);
        Route::post('blogs/upload-image', [BlogController::class, 'uploadImage']);
        Route::post('blogs/generate-ai', [BlogController::class, 'generateAI']);
        Route::get('blogs', [BlogController::class, 'index']);
        Route::post('blogs', [BlogController::class, 'store']);
        Route::get('blogs/{id}', [BlogController::class, 'show']);
        Route::put('blogs/{id}', [BlogController::class, 'update']);
        Route::delete('blogs/{id}', [BlogController::class, 'destroy']);

        // Blog Categories
        Route::get('blog-categories', [BlogController::class, 'listCategories']);
        Route::post('blog-categories', [BlogController::class, 'storeCategory']);
        Route::put('blog-categories/{id}', [BlogController::class, 'updateCategory']);
        Route::delete('blog-categories/{id}', [BlogController::class, 'destroyCategory']);

        // Blog Tags
        Route::get('blog-tags', [BlogController::class, 'listTags']);
        Route::post('blog-tags', [BlogController::class, 'storeTag']);
        Route::put('blog-tags/{id}', [BlogController::class, 'updateTag']);
        Route::delete('blog-tags/{id}', [BlogController::class, 'destroyTag']);

        // Blog Comments Moderation
        Route::get('blog-comments', [BlogController::class, 'adminComments']);
        Route::put('blog-comments/{id}/status', [BlogController::class, 'updateCommentStatus']);
        Route::delete('blog-comments/{id}', [BlogController::class, 'destroyComment']);

        // Payment Management
        Route::get('payments', [AdminController::class, 'payments']);

        // Gerber Management
        Route::get('gerber-files', [AdminController::class, 'gerberFiles']);
        Route::get('gerber-files/{id}/download', [AdminController::class, 'downloadGerberFile']);
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

        // JLCPCB Procurement & Pricing Settings Management
        Route::get('jlcpcb-settings', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'index']);
        Route::post('jlcpcb-settings', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'update']);
        Route::get('jlcpcb-settings/exchange-rate', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'fetchExchangeRate']);
        Route::post('jlcpcb-settings/gst-options', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'addGstOption']);
        Route::post('jlcpcb-settings/preview', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'calculatePreview']);
        Route::get('settings/jlcpcb-settings', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'index']);
        Route::post('settings/jlcpcb-settings', [\App\Http\Controllers\Admin\JlcpcbSettingsController::class, 'update']);

        // Holiday Management
        Route::get('holidays', [\App\Http\Controllers\HolidayController::class, 'index']);
        Route::post('holidays', [\App\Http\Controllers\HolidayController::class, 'store']);
        Route::get('holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'show']);
        Route::put('holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'update']);
        Route::delete('holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'destroy']);
        Route::put('holidays/{id}/status', [\App\Http\Controllers\HolidayController::class, 'toggleStatus']);

        Route::get('settings/holidays', [\App\Http\Controllers\HolidayController::class, 'index']);
        Route::post('settings/holidays', [\App\Http\Controllers\HolidayController::class, 'store']);
        Route::get('settings/holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'show']);
        Route::put('settings/holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'update']);
        Route::delete('settings/holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'destroy']);
        Route::put('settings/holidays/{id}/status', [\App\Http\Controllers\HolidayController::class, 'toggleStatus']);

        // DigiKey Products & Margin Management
        Route::get('digikey-products', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'index']);
        Route::put('digikey-products/{id}/margin', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'updateMargin']);
        Route::delete('digikey-products/{id}/margin', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'resetMargin']);
        Route::post('digikey-products/bulk-margin', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'bulkUpdateMargins']);
        Route::get('digikey-products/default-margin', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'getDefaultMargin']);
        Route::post('digikey-products/default-margin', [\App\Http\Controllers\AdminDigiKeyProductsController::class, 'updateDefaultMargin']);

        // Credentials Management
        Route::get('credentials', [\App\Http\Controllers\CredentialController::class, 'index']);
        Route::post('credentials', [\App\Http\Controllers\CredentialController::class, 'update']);
        Route::put('credentials', [\App\Http\Controllers\CredentialController::class, 'update']);
        Route::post('credentials/test-smtp', [\App\Http\Controllers\CredentialController::class, 'testSmtp']);
        Route::get('settings/credentials', [\App\Http\Controllers\CredentialController::class, 'index']);
        Route::post('settings/credentials', [\App\Http\Controllers\CredentialController::class, 'update']);
        Route::put('settings/credentials', [\App\Http\Controllers\CredentialController::class, 'update']);

        // Email Templates Management
        Route::get('email-templates', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'index']);
        Route::get('email-templates/{id}', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'show']);
        Route::put('email-templates/{id}', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'update']);
        Route::post('email-templates/{id}/preview', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'preview']);
        Route::post('email-templates/{id}/test', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'testEmail']);
        Route::post('email-templates/{id}/send-now', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'sendNow']);

        // Email Logs Management
        Route::get('email-logs/statistics', [\App\Http\Controllers\Admin\EmailLogController::class, 'statistics']);
        Route::get('email-logs/settings', [\App\Http\Controllers\Admin\EmailLogController::class, 'getSettings']);
        Route::post('email-logs/settings', [\App\Http\Controllers\Admin\EmailLogController::class, 'updateSettings']);
        Route::post('email-logs/cleanup', [\App\Http\Controllers\Admin\EmailLogController::class, 'cleanup']);
        Route::post('email-logs/bulk-delete', [\App\Http\Controllers\Admin\EmailLogController::class, 'bulkDelete']);
        Route::get('email-logs/export', [\App\Http\Controllers\Admin\EmailLogController::class, 'export']);
        Route::get('email-logs', [\App\Http\Controllers\Admin\EmailLogController::class, 'index']);
        Route::get('email-logs/{id}', [\App\Http\Controllers\Admin\EmailLogController::class, 'show']);
        Route::delete('email-logs/{id}', [\App\Http\Controllers\Admin\EmailLogController::class, 'destroy']);

        // Notification Management & Settings
        Route::get('notifications/unread-count', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'unreadCount']);
        Route::get('notifications/settings', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'getSettings']);
        Route::post('notifications/settings', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'updateSettings']);
        Route::post('notifications/cleanup', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'cleanup']);
        Route::post('notifications/read-all', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'markAllAsRead']);
        Route::get('notifications/stream', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'stream']);
        Route::get('notifications', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'index']);
        Route::post('notifications/{id}/read', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'markAsRead']);

        // System Health & Recovery Center
        Route::get('system-health', [\App\Http\Controllers\Admin\SystemHealthController::class, 'index']);
        Route::get('system-health/logs', [\App\Http\Controllers\Admin\SystemHealthController::class, 'logs']);
        Route::get('system-health/audit', [\App\Http\Controllers\Admin\SystemHealthController::class, 'audit']);
        Route::get('system-health/failed-jobs', [\App\Http\Controllers\Admin\SystemHealthController::class, 'failedJobs']);
        Route::post('system-health/maintenance', [\App\Http\Controllers\Admin\SystemHealthController::class, 'executeMaintenance']);
        Route::post('system-health/failed-jobs/action', [\App\Http\Controllers\Admin\SystemHealthController::class, 'failedJobsAction']);
        Route::post('system-health/test-mail', [\App\Http\Controllers\Admin\SystemHealthController::class, 'testMail']);
        Route::post('system-health/test-service', [\App\Http\Controllers\Admin\SystemHealthController::class, 'testExternalService']);
    });
});



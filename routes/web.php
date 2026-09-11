<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/optimize-clear', function () {
    Artisan::call('optimize:clear');
    return response()->json([
        'status' => true,
        'message' => 'Optimization cache cleared successfully!',
        'output' => Artisan::output()
    ]);
});

Route::get('/clear-cache', function () {
    Artisan::call('optimize:clear');
    return response()->json([
        'status' => true,
        'message' => 'Optimization cache cleared successfully!',
        'output' => Artisan::output()
    ]);
});

use App\Http\Controllers\BlogController;

Route::get('/blogs/first-10', [BlogController::class, 'firstTenBlogs']);
Route::get('/first-10', [BlogController::class, 'firstTenBlogs']);
Route::get('/open-blogs', [BlogController::class, 'firstTenBlogs']);

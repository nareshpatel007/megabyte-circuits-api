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

Route::get('/read-jobs', function () {
    $filePath = public_path('jobs.xlsx');

    if (!file_exists($filePath)) {
        return response()->json([
            'status' => false,
            'message' => 'jobs.xlsx file not found in public directory.'
        ], 404);
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error reading Excel file: ' . $e->getMessage()
        ], 500);
    }
});

Route::match(['GET', 'HEAD', 'OPTIONS'], '/storage/{path}', function (Illuminate\Http\Request $request, $path) {
    if ($request->isMethod('OPTIONS')) {
        return response('', 204)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
            ->header('Access-Control-Allow-Headers', '*');
    }

    // Sanitize path to prevent directory traversal
    $cleanPath = str_replace(['..', "\0"], '', (string)$path);
    $cleanPath = ltrim($cleanPath, '/\\');

    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    if ($disk->exists($cleanPath)) {
        return response()->file($disk->path($cleanPath), [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    $appPublic = storage_path('app/public/' . $cleanPath);
    if (file_exists($appPublic)) {
        return response()->file($appPublic, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    $pubStorage = public_path('storage/' . $cleanPath);
    if (file_exists($pubStorage)) {
        return response()->file($pubStorage, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    abort(404, 'File not found');
})->where('path', '.*');

Route::get('/storage-link', function () {
    try {
        Artisan::call('storage:link');
        return response()->json([
            'status' => true,
            'message' => 'Storage link command executed.',
            'output' => Artisan::output()
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

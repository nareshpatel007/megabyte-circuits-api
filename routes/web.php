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

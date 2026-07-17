<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        try {
            // Validate the request
            $validator = \Validator::make($request->all(), [
                'file' => 'required|file|mimes:zip,rar,7z,gz|max:10240', // Max 10MB
                'fileName' => 'nullable|string|max:255',
                'folder' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            if (!$request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'error' => 'No file provided'
                ], 400);
            }

            $file = $request->file('file');
            $originalName = $request->input('fileName', $file->getClientOriginalName());
            $folder = $request->input('folder', 'gerber-files');

            // Generate unique filename
            $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            
            // Store file in public disk
            $filePath = $file->storeAs($folder, $fileName, 'public');
            
            // Get the public URL
            $url = Storage::url($filePath);

            return response()->json([
                'success' => true,
                'folder' => $folder,
                'fileName' => $fileName,
                'originalName' => $originalName,
                'url' => $url,
                'path' => $filePath,
                'size' => $this->formatFileSize($file->getSize())
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload file',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function formatFileSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }
}

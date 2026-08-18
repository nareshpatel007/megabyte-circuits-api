<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:zip,rar,7z,gz|max:102400', // Max 100MB
                'fileName' => 'nullable|string|max:255',
                'folder' => 'nullable|string|max:100',
                'user_id' => 'nullable|integer',
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
            $userId = $request->input('user_id', null);

            // Generate unique filename
            $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();

            // Store file in public disk
            $filePath = $file->storeAs($folder, $fileName, 'public');

            // Get the public URL
            $url = Storage::url($filePath);
            $formattedSize = $this->formatFileSize($file->getSize());

            // Insert into gerber_files table
            $gerberFileId = DB::table('gerber_files')->insertGetId([
                'user_id' => $userId,
                'original_name' => $originalName,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $url,
                'file_size' => $formattedSize,
                'board_name' => pathinfo($originalName, PATHINFO_FILENAME),
                'preview_data' => $request->input('preview_data'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'gerber_file_id' => $gerberFileId,
                'folder' => $folder,
                'fileName' => $fileName,
                'originalName' => $originalName,
                'url' => $url,
                'path' => $filePath,
                'size' => $formattedSize
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

    public function updatePreview(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'gerber_file_id' => 'required|integer',
                'preview_data' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $gerberFileId = $request->input('gerber_file_id');
            $previewData = $request->input('preview_data');

            $updated = DB::table('gerber_files')
                ->where('id', $gerberFileId)
                ->update([
                    'preview_data' => $previewData,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Gerber preview updated successfully',
                'updated' => (bool)$updated
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update preview',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}


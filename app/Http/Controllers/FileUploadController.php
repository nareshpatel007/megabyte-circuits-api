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
            $originalExtension = strtolower($file->getClientOriginalExtension());
            $originalName = $request->input('fileName', $file->getClientOriginalName());
            $folder = $request->input('folder', 'gerber-files');
            $userId = $request->input('user_id', null);

            $zipFileUrl = null;
            $zipFilePath = null;

            // If RAR file, convert to ZIP or extract to ZIP format
            if ($originalExtension === 'rar') {
                try {
                    $realPath = $file->getRealPath();
                    $tempDir = storage_path('app/temp_rar_' . time() . '_' . Str::random(5));
                    if (!file_exists($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }

                    $unrarExec = null;
                    if (file_exists('C:\Program Files\WinRAR\UnRAR.exe')) {
                        $unrarExec = '"C:\Program Files\WinRAR\UnRAR.exe"';
                    } else if (file_exists('C:\Program Files (x86)\WinRAR\UnRAR.exe')) {
                        $unrarExec = '"C:\Program Files (x86)\WinRAR\UnRAR.exe"';
                    } else {
                        // Check Linux/macOS unrar
                        exec('which unrar 2>&1', $whichOutput, $whichReturn);
                        if ($whichReturn === 0) {
                            $unrarExec = 'unrar';
                        }
                    }

                    $extractedSuccess = false;

                    if ($unrarExec) {
                        // Extract RAR to temp directory using unrar x -y <archive> <output_dir>
                        $cmd = "{$unrarExec} x -y " . escapeshellarg($realPath) . " " . escapeshellarg($tempDir . DIRECTORY_SEPARATOR);
                        exec($cmd, $output, $returnVar);
                        if ($returnVar === 0) {
                            $extractedSuccess = true;
                        }
                    }

                    if (!$extractedSuccess && class_exists('RarArchive') && $rarArchive = \RarArchive::open($realPath)) {
                        $entries = $rarArchive->getEntries();
                        if ($entries !== false) {
                            foreach ($entries as $entry) {
                                if (!$entry->isDirectory()) {
                                    $extractedPath = $tempDir . '/' . basename($entry->getName());
                                    $entry->extract(false, $extractedPath);
                                }
                            }
                            $extractedSuccess = true;
                        }
                        $rarArchive->close();
                    }

                    if ($extractedSuccess) {
                        $zipFileName = time() . '_' . Str::random(10) . '.zip';
                        $zipFullPath = storage_path('app/public/' . $folder . '/' . $zipFileName);

                        $zip = new \ZipArchive();
                        if ($zip->open($zipFullPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                            $files = new \RecursiveIteratorIterator(
                                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                                \RecursiveIteratorIterator::LEAVES_ONLY
                            );

                            foreach ($files as $fileItem) {
                                if (!$fileItem->isDir()) {
                                    $filePathItem = $fileItem->getRealPath();
                                    $relativePath = substr($filePathItem, strlen($tempDir) + 1);
                                    $zip->addFile($filePathItem, $relativePath);
                                }
                            }
                            $zip->close();
                            $zipFilePath = $folder . '/' . $zipFileName;
                            $zipFileUrl = Storage::url($zipFilePath);
                        }
                    }

                    // Cleanup temp directory
                    if (file_exists($tempDir)) {
                        $files = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($files as $fileItem) {
                            $fileItem->isDir() ? rmdir($fileItem->getRealPath()) : unlink($fileItem->getRealPath());
                        }
                        rmdir($tempDir);
                    }
                } catch (\Exception $ex) {
                    // Log exception safely and continue standard upload
                    logger()->error("RAR to ZIP conversion error: " . $ex->getMessage());
                }
            }

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
                'zip_url' => $zipFileUrl,
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

    public function delete(Request $request)
    {
        try {
            $gerberFileId = $request->input('gerber_file_id') ?? $request->input('id');
            if (!$gerberFileId) {
                return response()->json([
                    'success' => false,
                    'error' => 'No gerber_file_id provided'
                ], 400);
            }

            $file = DB::table('gerber_files')->where('id', $gerberFileId)->first();
            if ($file) {
                if ($file->file_path && Storage::disk('public')->exists($file->file_path)) {
                    Storage::disk('public')->delete($file->file_path);
                }
                DB::table('gerber_files')->where('id', $gerberFileId)->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Gerber file deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete file',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}



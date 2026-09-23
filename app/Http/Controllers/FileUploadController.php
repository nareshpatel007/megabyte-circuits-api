<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        @set_time_limit(300);
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
            $fileToAnalyzePath = $file->getRealPath();
            $fileToAnalyzeName = $originalName;

            // If RAR file, convert to ZIP on backend using 7z, UnRAR, WinRAR or RarArchive
            if ($originalExtension === 'rar') {
                try {
                    $realPath = $file->getRealPath();
                    $tempDir = storage_path('app/temp_rar_' . time() . '_' . Str::random(5));
                    if (!file_exists($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }

                    $execCandidates = [
                        'C:\Program Files\7-Zip\7z.exe' => '7z',
                        'C:\Program Files (x86)\7-Zip\7z.exe' => '7z',
                        'C:\Program Files\WinRAR\UnRAR.exe' => 'unrar',
                        'C:\Program Files (x86)\WinRAR\UnRAR.exe' => 'unrar',
                        'C:\Program Files\WinRAR\WinRAR.exe' => 'winrar',
                        'C:\Program Files (x86)\WinRAR\WinRAR.exe' => 'winrar',
                    ];

                    $foundExec = null;
                    $foundType = null;
                    foreach ($execCandidates as $exePath => $type) {
                        if (file_exists($exePath)) {
                            $foundExec = '"' . $exePath . '"';
                            $foundType = $type;
                            break;
                        }
                    }

                    if (!$foundExec) {
                        exec('which unrar 2>&1', $whichOut1, $whichRet1);
                        if ($whichRet1 === 0) {
                            $foundExec = 'unrar';
                            $foundType = 'unrar';
                        } else {
                            exec('which 7z 2>&1', $whichOut2, $whichRet2);
                            if ($whichRet2 === 0) {
                                $foundExec = '7z';
                                $foundType = '7z';
                            }
                        }
                    }

                    $extractedSuccess = false;

                    if ($foundExec) {
                        if ($foundType === '7z') {
                            $cmd = "{$foundExec} e -y -o" . escapeshellarg($tempDir) . " " . escapeshellarg($realPath);
                        } else {
                            $cmd = "{$foundExec} x -y " . escapeshellarg($realPath) . " " . escapeshellarg($tempDir . DIRECTORY_SEPARATOR);
                        }
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
                            $zipFileUrl = Storage::url($folder . '/' . $zipFileName);
                            $fileToAnalyzePath = $zipFullPath;
                            $fileToAnalyzeName = str_replace('.rar', '.zip', $originalName);
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
                    logger()->error("Backend RAR to ZIP conversion error: " . $ex->getMessage());
                }
            }

            // Generate unique filename
            $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();

            // Store file in public disk
            $filePath = $file->storeAs($folder, $fileName, 'public');

            // Get the public URL
            $url = Storage::url($filePath);
            $formattedSize = $this->formatFileSize($file->getSize());

            // Create initial gerber_files record
            $gerberFileId = DB::table('gerber_files')->insertGetId([
                'user_id' => $userId,
                'original_name' => $originalName,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $url,
                'file_size' => $formattedSize,
                'board_name' => pathinfo($originalName, PATHINFO_FILENAME),
                'status' => 'processing',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Launch background process for Gerber analysis asynchronously (non-blocking)
            $artisanPath = base_path('artisan');
            $command = "php " . escapeshellarg($artisanPath) . " gerber:process " . (int)$gerberFileId;

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B {$command} > NUL 2>&1", "r"));
            } else {
                exec("{$command} > /dev/null 2>&1 &");
            }

            return response()->json([
                'success' => true,
                'status' => 'processing',
                'gerber_file_id' => $gerberFileId,
                'folder' => $folder,
                'fileName' => $fileName,
                'originalName' => $originalName,
                'url' => $url,
                'zip_url' => $zipFileUrl,
                'path' => $filePath,
                'size' => $formattedSize,
                'message' => 'File uploaded successfully. Processing started.'
            ], 200);

        } catch (\Exception $e) {
            logger()->error("FileUploadController upload exception: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload file',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function status(Request $request, $id)
    {
        try {
            $file = DB::table('gerber_files')->where('id', $id)->first();
            if (!$file) {
                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'error' => 'Gerber analysis record not found'
                ], 404);
            }

            // Auto timeout stuck processing status after 180 seconds
            if ($file->status === 'processing') {
                $createdAt = strtotime($file->created_at);
                if ($createdAt > 0 && (time() - $createdAt) > 180) {
                    DB::table('gerber_files')->where('id', $id)->update([
                        'status' => 'failed',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    $file->status = 'failed';
                }
            }

            $analysisData = $file->analysis_data ? json_decode($file->analysis_data, true) : null;
            $previewFrontRel = "/api/gerber/{$file->id}/preview/front";
            $previewBackRel = "/api/gerber/{$file->id}/preview/back";

            return response()->json([
                'success' => true,
                'status' => $file->status,
                'gerber_file_id' => $file->id,
                'python_project_id' => $file->python_project_id,
                'board_width' => $file->board_width,
                'board_height' => $file->board_height,
                'layer_count' => $file->layer_count,
                'preview_front' => $previewFrontRel,
                'preview_back' => $previewBackRel,
                'fileName' => $file->file_name,
                'originalName' => $file->original_name,
                'url' => $file->file_url,
                'path' => $file->file_path,
                'size' => $file->file_size,
                'analysis' => $analysisData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve analysis status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function previewImage(Request $request, $id, $side)
    {
        try {
            $file = DB::table('gerber_files')->where('id', $id)->first();
            if (!$file) {
                return response()->json(['error' => 'Record not found'], 404);
            }

            $analysisData = $file->analysis_data ? json_decode($file->analysis_data, true) : [];
            $pythonUrl = config('services.python_gerber.url', env('PYTHON_GERBER_API_URL', 'http://127.0.0.1:8000'));
            
            $relPath = null;
            if ($side === 'front') {
                $relPath = $file->front_preview_url ?? ($analysisData['preview_front'] ?? null);
            } else if ($side === 'back') {
                $relPath = $file->back_preview_url ?? ($analysisData['preview_back'] ?? null);
            }

            if (!$relPath && $file->python_project_id) {
                $relPath = ($side === 'back')
                    ? "/projects/{$file->python_project_id}/renders/pcb_bottom_2d.png"
                    : "/projects/{$file->python_project_id}/renders/pcb_top_2d.png";
            }

            if (!$relPath) {
                return response()->json(['error' => 'Preview not available'], 404);
            }

            // Fetch from Python service
            $targetUrl = rtrim($pythonUrl, '/') . '/' . ltrim($relPath, '/');
            $imgRes = Http::timeout(10)->get($targetUrl);

            if ($imgRes->successful()) {
                return response($imgRes->body(), 200)
                    ->header('Content-Type', 'image/png')
                    ->header('Cache-Control', 'public, max-age=86400');
            }

            return response()->json(['error' => 'Failed to load preview from analysis engine'], 404);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
            $gerberFileId = $request->input('gerber_file_id') ?? $request->input('file_id');
            $previewData = $request->input('preview_data');

            if (!$gerberFileId || !$previewData) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => ['gerber_file_id or file_id and preview_data are required']
                ], 422);
            }

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

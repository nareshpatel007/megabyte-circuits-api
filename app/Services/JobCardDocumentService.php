<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\PcbOrder;
use App\Models\JobCardDocument;
use App\Services\DocumentConversionService;
use App\Services\PdfMergeService;
use Barryvdh\DomPDF\Facade\Pdf;

class JobCardDocumentService
{
    protected DocumentConversionService $conversionService;
    protected PdfMergeService $mergeService;

    public function __construct(
        DocumentConversionService $conversionService,
        PdfMergeService $mergeService
    ) {
        $this->conversionService = $conversionService;
        $this->mergeService = $mergeService;
    }

    /**
     * Get list of attached documents for a Job Card order.
     */
    public function getDocumentsForOrder(PcbOrder $order)
    {
        return JobCardDocument::where('pcb_order_id', $order->id)
            ->where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get();
    }

    /**
     * Store an uploaded document (PDF, Word, or Image) for a Job Card order.
     */
    public function uploadDocument(PcbOrder $order, UploadedFile $file, ?int $adminId = null): JobCardDocument
    {
        // 1. Validate file extension
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("Invalid file extension. Only PDF, Word (.doc, .docx), and Image (.jpg, .jpeg, .png, .webp) files are allowed.");
        }

        // 2. Validate MIME type
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/octet-stream', // fallback for some browser uploads
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ];
        $mime = $file->getMimeType();
        $clientMime = $file->getClientMimeType();
        if (!in_array($mime, $allowedMimes) && !in_array($clientMime, $allowedMimes)) {
            throw new Exception("Invalid file type. Only PDF, Word, and Image documents are permitted.");
        }

        // 3. Validate size (Max 25MB)
        $maxBytes = 25 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new Exception("File exceeds the maximum upload limit of 25MB.");
        }

        $tempPath = $file->getRealPath();

        // 4. Validate file corruption before saving
        if ($extension === 'pdf') {
            $this->mergeService->validatePdfFile($tempPath, $file->getClientOriginalName());
        } else if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) || str_starts_with($mime, 'image/')) {
            $imgSize = @getimagesize($tempPath);
            if (!$imgSize) {
                throw new Exception("Document \"{$file->getClientOriginalName()}\" is not a valid or readable image file.");
            }
            if ($imgSize[0] > 10000 || $imgSize[1] > 10000) {
                throw new Exception("Image dimensions exceed the maximum allowed limit of 10000x10000 pixels.");
            }
        }

        // 5. Store file in dedicated order directory
        $orderDir = "job-cards/{$order->id}/attachments";
        $storedFilename = (in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) ? 'img_' : 'doc_') . uniqid() . '_' . time() . '.' . $extension;
        $storedPath = $file->storeAs($orderDir, $storedFilename, 'local');
        $absolutePath = storage_path('app/' . $storedPath);

        $sourceType = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) ? 'uploaded_image' : ('uploaded_' . $extension);
        $convertedPathRelative = null;
        $convertedFilename = null;
        $pdfPathForCounting = $absolutePath;

        // 6. Handle DOC/DOCX conversion to A4 PDF
        if (in_array($extension, ['doc', 'docx'])) {
            $convertedDir = "job-cards/{$order->id}/attachments/converted";
            $convertedFilename = uniqid('conv_') . '_' . time() . '.pdf';
            $convertedPathRelative = $convertedDir . '/' . $convertedFilename;
            $absoluteConvertedPath = storage_path('app/' . $convertedPathRelative);

            $absoluteConvertedDir = storage_path('app/' . $convertedDir);
            if (!is_dir($absoluteConvertedDir)) {
                @mkdir($absoluteConvertedDir, 0755, true);
            }

            $this->conversionService->convertDocToPdf($absolutePath, $absoluteConvertedPath);
            $pdfPathForCounting = $absoluteConvertedPath;
            $sourceType = 'converted_pdf';
        } 
        // Handle IMAGE conversion to A4 PDF page
        else if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            $convertedDir = "job-cards/{$order->id}/attachments/converted";
            $convertedFilename = uniqid('img_conv_') . '_' . time() . '.pdf';
            $convertedPathRelative = $convertedDir . '/' . $convertedFilename;
            $absoluteConvertedPath = storage_path('app/' . $convertedPathRelative);

            $absoluteConvertedDir = storage_path('app/' . $convertedDir);
            if (!is_dir($absoluteConvertedDir)) {
                @mkdir($absoluteConvertedDir, 0755, true);
            }

            $this->convertImageToA4Pdf($absolutePath, $absoluteConvertedPath);
            $pdfPathForCounting = $absoluteConvertedPath;
        }

        // 7. Get PDF page count (1 for images, accurate count for PDFs)
        $pageCount = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) 
            ? 1 
            : $this->mergeService->getPdfPageCount($pdfPathForCounting);

        // 8. Calculate sort_order
        $maxOrder = JobCardDocument::where('pcb_order_id', $order->id)->max('sort_order') ?: 1;
        $sortOrder = $maxOrder + 1;

        // 9. Save database record
        $document = JobCardDocument::create([
            'pcb_order_id' => $order->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedFilename,
            'file_path' => $storedPath,
            'mime_type' => $mime,
            'file_type' => $extension,
            'source_type' => $sourceType,
            'converted_pdf_path' => $convertedPathRelative,
            'converted_pdf_name' => $convertedFilename,
            'page_count' => $pageCount,
            'file_size' => $file->getSize(),
            'sort_order' => $sortOrder,
            'status' => 'active',
            'created_by' => $adminId,
        ]);

        $this->logAudit($order->id, $adminId, 'upload_document', [
            'document_id' => $document->id,
            'filename' => $file->getClientOriginalName(),
            'file_type' => $extension,
            'page_count' => $pageCount,
        ]);

        return $document;
    }

    /**
     * Convert an image file into a single A4 PDF page (fitted proportionally & centered).
     */
    public function convertImageToA4Pdf(string $sourceImagePath, string $outputPdfPath): void
    {
        if (!file_exists($sourceImagePath)) {
            throw new Exception("Source image file not found.");
        }

        $tempImgFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'norm_img_' . uniqid() . '.jpg';
        $this->normalizeAndRotateImage($sourceImagePath, $tempImgFile);

        try {
            $imgSize = @getimagesize($tempImgFile);
            if (!$imgSize) {
                throw new Exception("Failed to read normalized image dimensions.");
            }

            $srcW = (float)$imgSize[0];
            $srcH = (float)$imgSize[1];

            // Target Job Card A4 dimensions in mm
            $targetW = 216.0;
            $targetH = 279.0;

            // Calculate contain scaling (proportional)
            $scale = min($targetW / $srcW, $targetH / $srcH);
            $newW = $srcW * $scale;
            $newH = $srcH * $scale;

            // Center image horizontally and vertically
            $x = ($targetW - $newW) / 2.0;
            $y = ($targetH - $newH) / 2.0;

            $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', [$targetW, $targetH]);
            $pdf->SetAutoPageBreak(false);
            $pdf->AddPage('P', [$targetW, $targetH]);
            $pdf->Image($tempImgFile, $x, $y, $newW, $newH, 'JPG');

            $outputDir = dirname($outputPdfPath);
            if (!is_dir($outputDir)) {
                @mkdir($outputDir, 0755, true);
            }

            $pdf->Output('F', $outputPdfPath);
        } finally {
            if (file_exists($tempImgFile)) {
                @unlink($tempImgFile);
            }
        }
    }

    /**
     * Read, handle EXIF orientation, and normalize an image to JPEG for PDF embedding.
     */
    private function normalizeAndRotateImage(string $sourceImagePath, string $destJpegPath): void
    {
        $ext = strtolower(pathinfo($sourceImagePath, PATHINFO_EXTENSION));

        $gdImg = null;
        if (in_array($ext, ['jpg', 'jpeg'])) {
            $gdImg = @imagecreatefromjpeg($sourceImagePath);
        } else if ($ext === 'png') {
            $gdImg = @imagecreatefrompng($sourceImagePath);
        } else if ($ext === 'webp') {
            $gdImg = @imagecreatefromwebp($sourceImagePath);
        }

        if (!$gdImg) {
            $data = @file_get_contents($sourceImagePath);
            if ($data) {
                $gdImg = @imagecreatefromstring($data);
            }
        }

        if (!$gdImg) {
            throw new Exception("Unable to process image data.");
        }

        // Handle EXIF orientation for JPEG files
        if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($sourceImagePath);
                $orientation = $exif['Orientation'] ?? null;
                if ($orientation) {
                    switch ($orientation) {
                        case 3:
                            $gdImg = imagerotate($gdImg, 180, 0);
                            break;
                        case 6:
                            $gdImg = imagerotate($gdImg, -90, 0);
                            break;
                        case 8:
                            $gdImg = imagerotate($gdImg, 90, 0);
                            break;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore EXIF read errors if header absent
            }
        }

        $w = imagesx($gdImg);
        $h = imagesy($gdImg);

        // Downscale if image is larger than 3500px in either dimension to keep memory & print file reasonable
        $maxDim = 3500;
        if ($w > $maxDim || $h > $maxDim) {
            $scale = min($maxDim / $w, $maxDim / $h);
            $targetW = (int)round($w * $scale);
            $targetH = (int)round($h * $scale);

            $resized = imagecreatetruecolor($targetW, $targetH);
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefilledrectangle($resized, 0, 0, $targetW, $targetH, $white);
            imagecopyresampled($resized, $gdImg, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
            imagedestroy($gdImg);
            $gdImg = $resized;
            $w = $targetW;
            $h = $targetH;
        }

        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $gdImg, 0, 0, 0, 0, $w, $h);

        imagejpeg($canvas, $destJpegPath, 92);

        imagedestroy($gdImg);
        imagedestroy($canvas);
    }

    /**
     * Delete an attached document.
     */
    public function deleteDocument(PcbOrder $order, int $documentId, ?int $adminId = null): bool
    {
        $document = JobCardDocument::where('pcb_order_id', $order->id)
            ->where('id', $documentId)
            ->firstOrFail();

        // Remove original file
        if (!empty($document->file_path) && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        // Remove converted PDF file if applicable
        if (!empty($document->converted_pdf_path) && Storage::disk('local')->exists($document->converted_pdf_path)) {
            Storage::disk('local')->delete($document->converted_pdf_path);
        }

        $docName = $document->original_name;
        $document->delete();

        // Re-index remaining sort orders transactionally
        $remaining = JobCardDocument::where('pcb_order_id', $order->id)
            ->orderBy('sort_order', 'asc')
            ->get();

        DB::transaction(function () use ($remaining) {
            foreach ($remaining as $index => $doc) {
                $doc->update(['sort_order' => $index + 2]); // reserve sort_order 1 for Job Card default
            }
        });

        $this->logAudit($order->id, $adminId, 'delete_document', [
            'document_id' => $documentId,
            'filename' => $docName,
        ]);

        return true;
    }

    /**
     * Update sequence order of attached documents transactionally.
     */
    public function reorderDocuments(PcbOrder $order, array $documentOrders, ?int $adminId = null): void
    {
        DB::transaction(function () use ($order, $documentOrders) {
            foreach ($documentOrders as $item) {
                $docId = (int)($item['id'] ?? 0);
                $sortOrder = (int)($item['sort_order'] ?? 1);

                JobCardDocument::where('pcb_order_id', $order->id)
                    ->where('id', $docId)
                    ->update(['sort_order' => $sortOrder]);
            }
        });

        $this->logAudit($order->id, $adminId, 'reorder_documents', [
            'sequence' => $documentOrders,
        ]);
    }

    /**
     * Generate the combined PDF containing the generated Job Card + attached documents.
     */
    public function generateCombinedPdf(PcbOrder $order, array $jobCardData, array $documentSequence = [], ?int $adminId = null): string
    {
        // 1. Generate latest Job Card PDF binary & save temp file
        $jobCardPdfBinary = $this->generateJobCardPdfBinary($data = $jobCardData);

        $tempJobCardPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jobcard_main_' . uniqid() . '.pdf';
        file_put_contents($tempJobCardPath, $jobCardPdfBinary);

        $jobCardPageCount = $this->mergeService->getPdfPageCount($tempJobCardPath);

        // 2. Fetch order attached documents
        $documents = JobCardDocument::where('pcb_order_id', $order->id)
            ->where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get()
            ->keyBy('id');

        // 3. Build ordered array of PDFs to merge
        $filesToMerge = [];

        if (!empty($documentSequence)) {
            // Sequence provided from frontend order array: items can be 'job_card' or numeric document_id
            foreach ($documentSequence as $item) {
                if (is_array($item)) {
                    $type = $item['type'] ?? '';
                    $id = $item['id'] ?? null;
                } else {
                    $type = ($item === 'job_card' || $item === 0) ? 'job_card' : 'attachment';
                    $id = $item;
                }

                if ($type === 'job_card' || $id === 'job_card' || $id === 0) {
                    $filesToMerge[] = [
                        'path' => $tempJobCardPath,
                        'name' => "Job Card " . ($jobCardData['job_number'] ?? $order->order_number) . ".pdf"
                    ];
                } else if ($documents->has($id)) {
                    $doc = $documents->get($id);
                    $pdfFilePath = !empty($doc->converted_pdf_path) ? $doc->converted_pdf_path : $doc->file_path;
                    $absPath = storage_path('app/' . $pdfFilePath);
                    $filesToMerge[] = [
                        'path' => $absPath,
                        'name' => $doc->original_name
                    ];
                }
            }
        }

        // Fallback: If Job Card was not explicitly in sequence or sequence empty, put Job Card first then documents by sort_order
        $hasJobCard = false;
        foreach ($filesToMerge as $f) {
            if ($f['path'] === $tempJobCardPath) {
                $hasJobCard = true;
                break;
            }
        }

        if (!$hasJobCard) {
            array_unshift($filesToMerge, [
                'path' => $tempJobCardPath,
                'name' => "Job Card " . ($jobCardData['job_number'] ?? $order->order_number) . ".pdf"
            ]);

            // Add any missing attached documents in sort_order
            foreach ($documents as $doc) {
                $pdfFilePath = !empty($doc->converted_pdf_path) ? $doc->converted_pdf_path : $doc->file_path;
                $absPath = storage_path('app/' . $pdfFilePath);
                
                $alreadyAdded = false;
                foreach ($filesToMerge as $existing) {
                    if ($existing['path'] === $absPath) {
                        $alreadyAdded = true;
                        break;
                    }
                }

                if (!$alreadyAdded) {
                    $filesToMerge[] = [
                        'path' => $absPath,
                        'name' => $doc->original_name
                    ];
                }
            }
        }

        // 4. Merge all PDFs using PdfMergeService
        try {
            $combinedBinary = $this->mergeService->mergePdfs($filesToMerge);
            $this->logAudit($order->id, $adminId, 'generate_combined_pdf', [
                'total_documents' => count($filesToMerge),
            ]);
            return $combinedBinary;
        } finally {
            @unlink($tempJobCardPath);
        }
    }

    /**
     * Render the latest Job Card PDF binary from blade view.
     */
    public function generateJobCardPdfBinary(array $data): string
    {
        $pdf = Pdf::loadView('pdf.job-card', ['data' => $data]);
        $pdf->setPaper([0, 0, 612.28, 790.87], 'portrait');
        $pdf->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'serif',
            'dpi' => 150
        ]);

        return $pdf->output();
    }

    /**
     * Log action to audit table / logs.
     */
    private function logAudit(int $orderId, ?int $adminId, string $action, array $meta = []): void
    {
        try {
            DB::table('system_maintenance_logs')->insert([
                'action' => 'job_card_' . $action,
                'status' => 'success',
                'details' => json_encode(array_merge([
                    'order_id' => $orderId,
                    'admin_id' => $adminId,
                    'timestamp' => now()->toIso8601String(),
                ], $meta)),
                'executed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to log job card audit: " . $e->getMessage());
        }
    }
}

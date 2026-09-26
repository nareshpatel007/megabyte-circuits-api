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
     * Store an uploaded document for a Job Card order.
     */
    public function uploadDocument(PcbOrder $order, UploadedFile $file, ?int $adminId = null): JobCardDocument
    {
        // 1. Validate file extension
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['pdf', 'doc', 'docx'];
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("Invalid file extension. Only .pdf, .doc, and .docx files are allowed.");
        }

        // 2. Validate MIME type
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/octet-stream', // fallback for some browser uploads
        ];
        $mime = $file->getMimeType();
        if (!in_array($mime, $allowedMimes) && !in_array($file->getClientMimeType(), $allowedMimes)) {
            throw new Exception("Invalid file type. Only PDF and Word documents are permitted.");
        }

        // 3. Validate size (Max 25MB)
        $maxBytes = 25 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new Exception("File exceeds the maximum upload limit of 25MB.");
        }

        // 4. Validate file corruption before saving
        $tempPath = $file->getRealPath();
        if ($extension === 'pdf') {
            $this->mergeService->validatePdfFile($tempPath, $file->getClientOriginalName());
        }

        // 5. Store file in dedicated order directory
        $orderDir = "job-cards/{$order->id}/attachments";
        $storedFilename = uniqid('doc_') . '_' . time() . '.' . $extension;
        $storedPath = $file->storeAs($orderDir, $storedFilename, 'local');
        $absolutePath = storage_path('app/' . $storedPath);

        $sourceType = 'uploaded_' . $extension;
        $convertedPathRelative = null;
        $convertedFilename = null;
        $pdfPathForCounting = $absolutePath;

        // 6. Handle DOC/DOCX conversion
        if (in_array($extension, ['doc', 'docx'])) {
            $convertedDir = "job-cards/{$order->id}/attachments/converted";
            $convertedFilename = uniqid('conv_') . '_' . time() . '.pdf';
            $convertedPathRelative = $convertedDir . '/' . $convertedFilename;
            $absoluteConvertedPath = storage_path('app/' . $convertedPathRelative);

            // Ensure directory exists
            $absoluteConvertedDir = storage_path('app/' . $convertedDir);
            if (!is_dir($absoluteConvertedDir)) {
                @mkdir($absoluteConvertedDir, 0755, true);
            }

            // Perform conversion
            $this->conversionService->convertDocToPdf($absolutePath, $absoluteConvertedPath);
            $pdfPathForCounting = $absoluteConvertedPath;
            $sourceType = 'converted_pdf';
        }

        // 7. Get PDF page count
        $pageCount = $this->mergeService->getPdfPageCount($pdfPathForCounting);

        // 8. Calculate sort_order (put at end by default, Job Card is index 1 default)
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
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
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

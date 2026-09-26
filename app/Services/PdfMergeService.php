<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfMergeService
{
    /**
     * Get the accurate page count of a PDF file.
     *
     * @param string $pdfPath
     * @return int
     * @throws Exception
     */
    public function getPdfPageCount(string $pdfPath): int
    {
        if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
            throw new Exception("PDF file does not exist or is empty.");
        }

        $this->validatePdfHeader($pdfPath);

        try {
            $pdf = new Fpdi();
            return (int)$pdf->setSourceFile($pdfPath);
        } catch (\Throwable $e) {
            Log::error("Failed to count PDF pages for {$pdfPath}: " . $e->getMessage());
            throw new Exception("PDF file is invalid or corrupted.");
        }
    }

    /**
     * Validate a PDF file without throwing fatal errors.
     *
     * @param string $pdfPath
     * @param string $originalName
     * @return void
     * @throws Exception
     */
    public function validatePdfFile(string $pdfPath, string $originalName = 'Document'): void
    {
        if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
            throw new Exception("Document \"{$originalName}\" could not be processed (file missing or 0 bytes).");
        }

        $handle = fopen($pdfPath, 'rb');
        if (!$handle) {
            throw new Exception("Document \"{$originalName}\" could not be opened.");
        }
        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new Exception("Document \"{$originalName}\" could not be processed (invalid PDF header).");
        }

        try {
            $pdf = new Fpdi();
            $pages = $pdf->setSourceFile($pdfPath);
            if ($pages <= 0) {
                throw new Exception("Document \"{$originalName}\" has no pages.");
            }
        } catch (\Throwable $e) {
            throw new Exception("Document \"{$originalName}\" could not be processed (" . $e->getMessage() . ").");
        }
    }

    /**
     * Merge multiple PDF files in the exact provided order into a single PDF output.
     *
     * @param array $filesArray Array of items: ['path' => string, 'name' => string]
     * @param string|null $outputPath Destination file path or null to return string
     * @return string Merged PDF binary content
     * @throws Exception
     */
    public function mergePdfs(array $filesArray, ?string $outputPath = null): string
    {
        if (empty($filesArray)) {
            throw new Exception("No PDF files provided for merging.");
        }

        // Validate all files first before merging to ensure all are valid (Requirement 21)
        foreach ($filesArray as $fileItem) {
            $path = $fileItem['path'] ?? '';
            $name = $fileItem['name'] ?? 'Document';
            $this->validatePdfFile($path, $name);
        }

        try {
            $pdf = new Fpdi();

            foreach ($filesArray as $fileItem) {
                $filePath = $fileItem['path'];
                $pageCount = $pdf->setSourceFile($filePath);

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);

                    $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                }
            }

            if (!empty($outputPath)) {
                $dir = dirname($outputPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $pdf->Output('F', $outputPath);
                return file_get_contents($outputPath);
            }

            return $pdf->Output('S');
        } catch (\Throwable $e) {
            Log::error("PDF merge failed: " . $e->getMessage());
            throw new Exception("Unable to generate the combined Job Card PDF: " . $e->getMessage());
        }
    }

    /**
     * Validate that file starts with PDF signature (%PDF-).
     */
    private function validatePdfHeader(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new Exception("Unable to open PDF file.");
        }
        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new Exception("File is not a valid PDF document.");
        }
    }
}

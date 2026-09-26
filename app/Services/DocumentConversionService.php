<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;

class DocumentConversionService
{
    /**
     * Convert a DOC or DOCX file to PDF format.
     *
     * @param string $sourcePath Absolute path to source .doc or .docx file
     * @param string $outputPath Absolute path to destination .pdf file
     * @return bool
     * @throws Exception
     */
    public function convertDocToPdf(string $sourcePath, string $outputPath): bool
    {
        if (!file_exists($sourcePath)) {
            throw new Exception("Source document file not found.");
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['doc', 'docx'])) {
            throw new Exception("Unsupported file type for conversion. Only .doc and .docx are supported.");
        }

        // Try CLI conversion (LibreOffice / soffice) if available
        $cliSuccess = $this->convertWithLibreOffice($sourcePath, $outputPath);
        if ($cliSuccess && file_exists($outputPath) && filesize($outputPath) > 0) {
            $this->validatePdfHeader($outputPath);
            return true;
        }

        // Fallback: Pure PHP conversion (PhpOffice\PhpWord + Dompdf)
        $phpSuccess = $this->convertWithPhpWord($sourcePath, $outputPath);
        if ($phpSuccess && file_exists($outputPath) && filesize($outputPath) > 0) {
            $this->validatePdfHeader($outputPath);
            return true;
        }

        throw new Exception("Unable to convert the Word document to PDF. Please verify that the document is valid.");
    }

    /**
     * Convert using LibreOffice / soffice CLI if installed on the host.
     */
    private function convertWithLibreOffice(string $sourcePath, string $outputPath): bool
    {
        $bin = $this->detectLibreOfficeBinary();
        if (!$bin) {
            return false;
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'doc_conv_' . uniqid();
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        try {
            $cmd = sprintf(
                '%s --headless --norestore --nofirststartwizard --convert-to pdf --outdir %s %s',
                escapeshellcmd($bin),
                escapeshellarg($tempDir),
                escapeshellarg($sourcePath)
            );

            $output = [];
            $returnCode = 1;
            @exec($cmd, $output, $returnCode);

            $generatedPdfName = pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
            $generatedPdfPath = $tempDir . DIRECTORY_SEPARATOR . $generatedPdfName;

            if ($returnCode === 0 && file_exists($generatedPdfPath) && filesize($generatedPdfPath) > 0) {
                // Move generated file to output path
                $outputDir = dirname($outputPath);
                if (!is_dir($outputDir)) {
                    @mkdir($outputDir, 0755, true);
                }
                copy($generatedPdfPath, $outputPath);
                @unlink($generatedPdfPath);
                @rmdir($tempDir);
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning("LibreOffice DOC conversion failed: " . $e->getMessage());
        } finally {
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }

        return false;
    }

    /**
     * Convert using PhpOffice\PhpWord and Dompdf as pure PHP fallback.
     */
    private function convertWithPhpWord(string $sourcePath, string $outputPath): bool
    {
        try {
            // Load word document
            $phpWord = PhpWordIOFactory::load($sourcePath);

            // Save to temporary HTML string
            $htmlWriter = PhpWordIOFactory::createWriter($phpWord, 'HTML');
            
            $tempHtmlPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpword_' . uniqid() . '.html';
            $htmlWriter->save($tempHtmlPath);

            $htmlContent = file_get_contents($tempHtmlPath);
            @unlink($tempHtmlPath);

            if (empty(trim($htmlContent))) {
                throw new Exception("Extracted HTML content from document is empty.");
            }

            // Wrap in styled container for standard rendering
            $styledHtml = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: sans-serif; font-size: 11pt; line-height: 1.5; color: #111827; padding: 20px; }
                    p { margin-bottom: 10px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                    th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
                    th { background-color: #f3f4f6; }
                </style>
            </head>
            <body>' . $htmlContent . '</body>
            </html>';

            $pdf = Pdf::loadHTML($styledHtml);
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150
            ]);

            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                @mkdir($outputDir, 0755, true);
            }

            file_put_contents($outputPath, $pdf->output());

            return file_exists($outputPath) && filesize($outputPath) > 0;
        } catch (\Throwable $e) {
            Log::error("PhpWord pure PHP conversion failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Detect if LibreOffice or soffice executable exists on system.
     */
    private function detectLibreOfficeBinary(): ?string
    {
        $possiblePaths = [
            'soffice',
            'libreoffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];

        foreach ($possiblePaths as $path) {
            if ($path === 'soffice' || $path === 'libreoffice') {
                $where = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where.exe' : 'which';
                $out = [];
                $res = 1;
                @exec("{$where} " . escapeshellarg($path), $out, $res);
                if ($res === 0 && !empty($out[0])) {
                    return trim($out[0]);
                }
            } else if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Validate that file starts with PDF signature (%PDF-).
     */
    private function validatePdfHeader(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new Exception("Unable to open converted PDF file.");
        }
        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new Exception("Converted output file is not a valid PDF document.");
        }
    }

    /**
     * Helper to recursively delete temporary directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

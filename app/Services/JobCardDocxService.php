<?php

namespace App\Services;

use Exception;
use App\Models\PcbOrder;
use App\Models\JobCardDocument;
use Carbon\Carbon;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use PhpOffice\PhpWord\Shared\Converter;

class JobCardDocxService
{
    /**
     * Generate an editable DOCX Word document representing the Job Card.
     * Fully compliant with Microsoft Word OpenXML schema standards.
     *
     * @param array $jobCardData Structured Job Card specifications
     * @param PcbOrder $order Order model
     * @return string DOCX binary content
     */
    public function generateDocx(array $jobCardData, PcbOrder $order): string
    {
        $phpWord = new PhpWord();

        // Document Metadata
        $docInfo = $phpWord->getDocInfo();
        $docInfo->setCreator('Megabyte Circuits');
        $docInfo->setCompany('Megabyte Circuits');
        $docInfo->setTitle('JOB CARD ' . ($jobCardData['job_number'] ?? $order->order_number));

        // Global styles
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(9.5);

        // Section setup: A4 Portrait (210mm x 297mm) with 12mm margins
        $section = $phpWord->addSection([
            'pageSizeW' => Converter::cmToTwip(21.0),
            'pageSizeH' => Converter::cmToTwip(29.7),
            'marginTop' => Converter::cmToTwip(1.2),
            'marginBottom' => Converter::cmToTwip(1.2),
            'marginLeft' => Converter::cmToTwip(1.2),
            'marginRight' => Converter::cmToTwip(1.2),
        ]);

        // Standard Table styling
        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMarginTop' => Converter::cmToTwip(0.12),
            'cellMarginBottom' => Converter::cmToTwip(0.12),
            'cellMarginLeft' => Converter::cmToTwip(0.15),
            'cellMarginRight' => Converter::cmToTwip(0.15),
        ];

        $headerBg = '000000';

        // Helper function for safe non-empty text
        $safeText = function ($text, $fallback = ' ') {
            $str = trim((string)$text);
            return $str !== '' ? $str : $fallback;
        };

        // 1. Header Box Table (3 Columns)
        $headerTable = $section->addTable([
            'borderSize' => 12,
            'borderColor' => '000000',
            'cellMarginTop' => Converter::cmToTwip(0.15),
            'cellMarginBottom' => Converter::cmToTwip(0.15),
            'cellMarginLeft' => Converter::cmToTwip(0.2),
            'cellMarginRight' => Converter::cmToTwip(0.2),
        ]);
        $headerRow = $headerTable->addRow(Converter::cmToTwip(0.8));
        $c1 = $headerRow->addCell(3000, ['valign' => VerticalJc::CENTER]);
        $c1->addText('JOB NO: ' . $safeText($jobCardData['job_number'] ?? $order->order_number), ['bold' => true, 'size' => 11]);

        $c2 = $headerRow->addCell(4500, ['valign' => VerticalJc::CENTER]);
        $procText = '';
        if (!empty($jobCardData['expose'])) $procText .= '[X] Expose  ';
        if (!empty($jobCardData['print_and_etch'])) $procText .= '[X] Print & Etch';
        if (empty($procText)) $procText = 'Standard Process';
        $c2->addText($safeText($procText), ['size' => 10, 'bold' => true], ['alignment' => Jc::CENTER]);

        $c3 = $headerRow->addCell(2500, ['valign' => VerticalJc::CENTER]);
        $c3->addText($safeText($jobCardData['job_type'] ?? 'JOB CARD'), ['bold' => true, 'size' => 11], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);

        // 2. Title Banner
        $section->addText('JOB CARD', ['bold' => true, 'size' => 16, 'underline' => 'single'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        // 3. Specifications Block A: Dates & Qty Specs (3 Equal Columns)
        $tableA = $section->addTable($tableStyle);
        $tableA->addRow();
        $tableA->addCell(3333)->addText('Order Date: ' . $safeText($this->formatDateOnly($jobCardData['order_date'] ?? null)), ['bold' => true]);
        $tableA->addCell(3333)->addText('Launch Date: ' . $safeText($this->formatDateOnly($jobCardData['launch_date'] ?? null)), ['bold' => true]);
        $tableA->addCell(3334)->addText('Shipping Date: ' . $safeText($this->formatDateOnly($jobCardData['shipping_date'] ?? null)), ['bold' => true]);

        $tableA->addRow();
        $tableA->addCell(3333)->addText('ORDER QTY: ' . $safeText($jobCardData['order_qty'] ?? ''), ['bold' => true]);
        $tableA->addCell(3333)->addText('LAUNCHED: ' . $safeText($jobCardData['launched_qty'] ?? ''), ['bold' => true]);
        $tableA->addCell(3334)->addText(
            'UPS: ' . $safeText($jobCardData['ups'] ?? '') . '  |  PANELS: ' . $safeText($jobCardData['panels'] ?? '') . '  |  MIN HOLE: ' . $safeText($jobCardData['min_hole'] ?? ''),
            ['bold' => true]
        );

        $section->addTextBreak(1);

        // 4. Specifications Block B: Panel & Cutting Dimensions (2 Equal Columns)
        $tableB = $section->addTable($tableStyle);
        $tableB->addRow();
        $tableB->addCell(5000)->addText('PANEL SIZE: ' . $safeText($jobCardData['panel_size'] ?? ''));
        $tableB->addCell(5000)->addText('CUTTING SIZE: ' . $safeText($jobCardData['cutting_size'] ?? ''));

        $section->addTextBreak(1);

        // 5. Specifications Block C: Material & Finish Options (4 Equal Columns)
        $tableC = $section->addTable($tableStyle);
        $tableC->addRow();
        $tableC->addCell(2500)->addText('MATERIAL: ' . $safeText($jobCardData['material'] ?? ''));
        $tableC->addCell(2500)->addText('THICK: ' . $safeText($jobCardData['thickness'] ?? ''));
        $tableC->addCell(2500)->addText('COPPER THICK: ' . $safeText($jobCardData['copper_thickness'] ?? ''));
        $tableC->addCell(2500)->addText('FINISH: ' . $safeText($jobCardData['finish'] ?? ''));

        $section->addTextBreak(1);

        // 6. Specifications Block D: Mask & Legend Specs (3 Equal Columns)
        $tableD = $section->addTable($tableStyle);
        $tableD->addRow();
        $tableD->addCell(3333)->addText('MASK COLOUR: ' . $safeText($jobCardData['mask_colour'] ?? ''));
        $tableD->addCell(3333)->addText('LP COLOR: ' . $safeText($jobCardData['lp_color'] ?? ''));
        $tableD->addCell(3334)->addText('LP SIDE: ' . $safeText($jobCardData['lp_side'] ?? ''));

        $section->addTextBreak(1);

        // 7. Specifications Block E: Fabrication Options (4 Equal Columns)
        $tableE = $section->addTable($tableStyle);
        $tableE->addRow();
        $tableE->addCell(2500)->addText('ROUTE: ' . $safeText($jobCardData['route'] ?? ''));
        $tableE->addCell(2500)->addText('V-CUT: ' . $safeText($jobCardData['v_cut'] ?? ''));
        $tableE->addCell(2500)->addText('SHEARING CUT: ' . $safeText($jobCardData['shearing_cut'] ?? ''));
        $tableE->addCell(2500)->addText('INTERNAL CUTOUTS: ' . $safeText($jobCardData['internal_cutouts'] ?? ''));

        $section->addTextBreak(1);

        // 8. Notes Section Table (2 Equal Columns)
        $notesTable = $section->addTable($tableStyle);
        $notesTable->addRow();
        $cNotes1 = $notesTable->addCell(5000);
        $cNotes1->addText('PRODUCTION NOTE:', ['bold' => true, 'size' => 9]);
        $cNotes1->addText($safeText($jobCardData['production_note'] ?? ''), ['size' => 9]);

        $cNotes2 = $notesTable->addCell(5000);
        $cNotes2->addText('CUSTOMER SPECIAL NOTE:', ['bold' => true, 'size' => 9]);
        $cNotes2->addText($safeText($jobCardData['customer_note'] ?? ''), ['size' => 9]);

        $section->addTextBreak(1);

        // 9. Final Qty & Rejection Summary Table (4 Equal Columns)
        $finalTable = $section->addTable($tableStyle);
        $finalTable->addRow();
        $finalTable->addCell(2500)->addText('FINAL PANEL QTY: ' . $safeText($jobCardData['final_panel_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('FINAL BOARD QTY: ' . $safeText($jobCardData['final_board_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('REJECTED BOARD QTY: ' . $safeText($jobCardData['rejected_board_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('WHY REJECTED: ' . $safeText($jobCardData['why_rejected'] ?? ''));

        $section->addTextBreak(1);

        // 10. Manufacturing Process Routing Log Table (8 Equal Width Header/Data Rows)
        $section->addText('MANUFACTURING PROCESS ROUTING LOG', ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $procTable = $section->addTable($tableStyle);
        $headerCellOpts = ['bgColor' => $headerBg, 'valign' => VerticalJc::CENTER];
        $headerTextOpts = ['bold' => true, 'color' => 'FFFFFF', 'size' => 9];

        $procTable->addRow(Converter::cmToTwip(0.6));
        $procTable->addCell(2500, $headerCellOpts)->addText('PROCESS', $headerTextOpts, ['alignment' => Jc::LEFT]);
        $procTable->addCell(800, $headerCellOpts)->addText('IN', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1100, $headerCellOpts)->addText('PANEL QTY', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(800, $headerCellOpts)->addText('OUT', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1100, $headerCellOpts)->addText('PANEL QTY', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(800, $headerCellOpts)->addText('Q.C', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1100, $headerCellOpts)->addText('SIGN', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1800, $headerCellOpts)->addText('REMARK', $headerTextOpts, ['alignment' => Jc::LEFT]);

        $processes = $jobCardData['processes'] ?? [];
        foreach ($processes as $p) {
            $procTable->addRow(Converter::cmToTwip(0.55));
            $procTable->addCell(2500)->addText($safeText($p['process'] ?? ''), ['bold' => true, 'size' => 8.5]);
            $procTable->addCell(800)->addText($safeText($p['in'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($safeText($p['panel_qty_in'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($safeText($p['out'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($safeText($p['panel_qty_out'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($safeText($p['qc'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($safeText($p['sign'] ?? ''), ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1800)->addText($safeText($p['remark'] ?? ''), ['size' => 8.5]);
        }

        // 11. Attached Images (if any active image documents exist for order)
        try {
            $attachedImages = JobCardDocument::where('pcb_order_id', $order->id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereIn('file_type', ['jpg', 'jpeg', 'png', 'webp'])
                      ->orWhere('source_type', 'like', '%image%');
                })
                ->orderBy('sort_order', 'asc')
                ->get();

            foreach ($attachedImages as $imgDoc) {
                $imgPath = storage_path('app/' . $imgDoc->file_path);
                if (file_exists($imgPath) && filesize($imgPath) > 0) {
                    $section->addPageBreak();
                    $section->addText('ATTACHMENT: ' . $safeText($imgDoc->original_name), ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
                    $section->addTextBreak(1);

                    // Insert image scaled cleanly
                    $section->addImage($imgPath, [
                        'width' => 480,
                        'alignment' => Jc::CENTER,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Silently skip image embedding if any filesystem issue
        }

        // Generate DOCX binary using standard Word2007 writer
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jobcard_' . uniqid() . '.docx';
        $writer->save($tempPath);

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return $content;
    }

    /**
     * Helper to format date to 'DD MMM YYYY' without time.
     */
    private function formatDateOnly($val): string
    {
        if (empty($val)) return '';
        try {
            return Carbon::parse($val)->format('d M Y');
        } catch (\Throwable $e) {
            $cleaned = preg_replace('/,?\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm|AM|PM)?/i', '', (string)$val);
            return trim($cleaned);
        }
    }
}

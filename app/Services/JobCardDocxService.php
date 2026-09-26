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
     *
     * @param array $jobCardData Structured Job Card specifications
     * @param PcbOrder $order Order model
     * @return string DOCX binary content
     */
    public function generateDocx(array $jobCardData, PcbOrder $order): string
    {
        $phpWord = new PhpWord();

        // Global styles
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(9.5);

        // Section setup: A4 Portrait (210mm x 297mm) with 15mm margins
        $section = $phpWord->addSection([
            'pageSizeW' => Converter::cmToTwip(21.0),
            'pageSizeH' => Converter::cmToTwip(29.7),
            'marginTop' => Converter::cmToTwip(1.2),
            'marginBottom' => Converter::cmToTwip(1.2),
            'marginLeft' => Converter::cmToTwip(1.2),
            'marginRight' => Converter::cmToTwip(1.2),
        ]);

        // Table styles
        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMarginTop' => Converter::cmToTwip(0.1),
            'cellMarginBottom' => Converter::cmToTwip(0.1),
            'cellMarginLeft' => Converter::cmToTwip(0.15),
            'cellMarginRight' => Converter::cmToTwip(0.15),
        ];

        $headerBg = '000000';
        $subHeaderBg = 'F3F4F6';
        $lightBg = 'FAFAFA';

        // 1. Header Box
        $headerTable = $section->addTable(['borderSize' => 12, 'borderColor' => '000000', 'width' => 100 * 50, 'unit' => 'pct']);
        $headerRow = $headerTable->addRow();
        $cell1 = $headerRow->addCell(3000, ['valign' => VerticalJc::CENTER]);
        $cell1->addText('JOB NO: ' . ($jobCardData['job_number'] ?? $order->order_number), ['bold' => true, 'size' => 12]);

        $cell2 = $headerRow->addCell(4000, ['valign' => VerticalJc::CENTER]);
        $procText = '';
        if (!empty($jobCardData['expose'])) $procText .= '[X] Expose  ';
        if (!empty($jobCardData['print_and_etch'])) $procText .= '[X] Print & Etch';
        $cell2->addText(trim($procText), ['size' => 10, 'bold' => true], ['alignment' => Jc::CENTER]);

        $cell3 = $headerRow->addCell(3000, ['valign' => VerticalJc::CENTER]);
        $cell3->addText($jobCardData['job_type'] ?? 'JOB CARD', ['bold' => true, 'size' => 12], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);

        // 2. Title Banner
        $section->addText('JOB CARD', ['bold' => true, 'size' => 16, 'underline' => 'single'], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        // 3. Dates & Core Quantities Table
        $infoTable = $section->addTable($tableStyle);

        // Row 1: Dates
        $infoTable->addRow();
        $infoTable->addCell(3333)->addText('Order Date: ' . $this->formatDateOnly($jobCardData['order_date'] ?? null), ['bold' => true]);
        $infoTable->addCell(3333)->addText('Launch Date: ' . $this->formatDateOnly($jobCardData['launch_date'] ?? null), ['bold' => true]);
        $infoTable->addCell(3334)->addText('Shipping Date: ' . $this->formatDateOnly($jobCardData['shipping_date'] ?? null), ['bold' => true]);

        // Row 2: Qty Specs
        $infoTable->addRow();
        $infoTable->addCell(3333)->addText('ORDER QTY: ' . ($jobCardData['order_qty'] ?? ''), ['bold' => true]);
        $infoTable->addCell(3333)->addText('LAUNCHED: ' . ($jobCardData['launched_qty'] ?? ''), ['bold' => true]);
        $infoTable->addCell(3334)->addText('UPS: ' . ($jobCardData['ups'] ?? '') . '   |   PANELS: ' . ($jobCardData['panels'] ?? '') . '   |   MIN HOLE: ' . ($jobCardData['min_hole'] ?? ''), ['bold' => true]);

        // Row 3: Dimensions
        $infoTable->addRow();
        $infoTable->addCell(5000, ['gridSpan' => 2])->addText('PANEL SIZE: ' . ($jobCardData['panel_size'] ?? ''));
        $infoTable->addCell(5000)->addText('CUTTING SIZE: ' . ($jobCardData['cutting_size'] ?? ''));

        // Row 4: Materials
        $infoTable->addRow();
        $infoTable->addCell(2500)->addText('MATERIAL: ' . ($jobCardData['material'] ?? ''));
        $infoTable->addCell(2500)->addText('THICK: ' . ($jobCardData['thickness'] ?? ''));
        $infoTable->addCell(2500)->addText('COPPER THICK: ' . ($jobCardData['copper_thickness'] ?? ''));
        $infoTable->addCell(2500)->addText('FINISH: ' . ($jobCardData['finish'] ?? ''));

        // Row 5: Mask & LP
        $infoTable->addRow();
        $infoTable->addCell(3333)->addText('MASK COLOUR: ' . ($jobCardData['mask_colour'] ?? ''));
        $infoTable->addCell(3333)->addText('LP COLOR: ' . ($jobCardData['lp_color'] ?? ''));
        $infoTable->addCell(3334)->addText('LP SIDE: ' . ($jobCardData['lp_side'] ?? ''));

        // Row 6: Fabrication Options
        $infoTable->addRow();
        $infoTable->addCell(2500)->addText('ROUTE: ' . ($jobCardData['route'] ?? ''));
        $infoTable->addCell(2500)->addText('V-CUT: ' . ($jobCardData['v_cut'] ?? ''));
        $infoTable->addCell(2500)->addText('SHEARING CUT: ' . ($jobCardData['shearing_cut'] ?? ''));
        $infoTable->addCell(2500)->addText('INTERNAL CUTOUTS: ' . ($jobCardData['internal_cutouts'] ?? ''));

        $section->addTextBreak(1);

        // 4. Notes Section
        $notesTable = $section->addTable($tableStyle);
        $notesTable->addRow();
        $cNotes1 = $notesTable->addCell(5000);
        $cNotes1->addText('PRODUCTION NOTE:', ['bold' => true, 'size' => 9]);
        $cNotes1->addText($jobCardData['production_note'] ?? '', ['size' => 9]);

        $cNotes2 = $notesTable->addCell(5000);
        $cNotes2->addText('CUSTOMER SPECIAL NOTE:', ['bold' => true, 'size' => 9]);
        $cNotes2->addText($jobCardData['customer_note'] ?? '', ['size' => 9]);

        $section->addTextBreak(1);

        // 5. Final Qty & Rejection Summary
        $finalTable = $section->addTable($tableStyle);
        $finalTable->addRow();
        $finalTable->addCell(2500)->addText('FINAL PANEL QTY: ' . ($jobCardData['final_panel_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('FINAL BOARD QTY: ' . ($jobCardData['final_board_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('REJECTED BOARD QTY: ' . ($jobCardData['rejected_board_qty'] ?? ''), ['bold' => true]);
        $finalTable->addCell(2500)->addText('WHY REJECTED: ' . ($jobCardData['why_rejected'] ?? ''));

        $section->addTextBreak(1);

        // 6. Manufacturing Process Routing Log Table
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
            $procTable->addCell(2500)->addText($p['process'] ?? '', ['bold' => true, 'size' => 8.5]);
            $procTable->addCell(800)->addText($p['in'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($p['panel_qty_in'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($p['out'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($p['panel_qty_out'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($p['qc'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($p['sign'] ?? '', ['size' => 8.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1800)->addText($p['remark'] ?? '', ['size' => 8.5]);
        }

        // 7. Attached Images (if any attached image documents exist for order)
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
                    $section->addText('ATTACHMENT: ' . $imgDoc->original_name, ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
                    $section->addTextBreak(1);

                    // Insert image scaled to fit page width
                    $section->addImage($imgPath, [
                        'width' => 480,
                        'alignment' => Jc::CENTER,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Silently skip image embedding if any error
        }

        // Generate DOCX binary
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

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
use Smalot\PdfParser\Parser as PdfParser;

class JobCardDocxService
{
    /**
     * Generate an editable DOCX Word document representing the Job Card.
     * Fully compliant with Microsoft Word OpenXML schema standards.
     * Fits cleanly on a single A4 page and appends all attached documents.
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
        $phpWord->setDefaultFontSize(8.5);

        // Section setup: A4 Portrait (210mm x 297mm) with compact margins (0.5cm top/bottom, 0.8cm left/right)
        $section = $phpWord->addSection([
            'pageSizeW' => Converter::cmToTwip(21.0),
            'pageSizeH' => Converter::cmToTwip(29.7),
            'marginTop' => Converter::cmToTwip(0.5),
            'marginBottom' => Converter::cmToTwip(0.5),
            'marginLeft' => Converter::cmToTwip(0.8),
            'marginRight' => Converter::cmToTwip(0.8),
        ]);

        // Standard Table styling (total table width ~ 11,000 twips = 19.4cm)
        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMarginTop' => Converter::cmToTwip(0.04),
            'cellMarginBottom' => Converter::cmToTwip(0.04),
            'cellMarginLeft' => Converter::cmToTwip(0.10),
            'cellMarginRight' => Converter::cmToTwip(0.10),
        ];

        $headerBg = '000000';

        // Helper function for safe non-empty text (strips invalid XML control characters)
        $safeText = function ($text, $fallback = '') {
            if ($text === null) return $fallback;
            $str = (string)$text;
            // Strip XML control characters (0x00-0x08, 0x0B-0x0C, 0x0E-0x1F, 0x7F)
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
            $clean = trim($clean);
            return $clean !== '' ? $clean : $fallback;
        };

        // Format dimension units (MM)
        $formatMm = function ($val) use ($safeText) {
            $v = $safeText($val);
            if ($v === '') return '';
            return str_contains(strtoupper($v), 'MM') ? $v : $v . ' MM';
        };

        // Format copper thickness units (Micron / oz)
        $formatCopper = function ($val) use ($safeText) {
            $v = $safeText($val);
            if ($v === '') return '';
            $u = strtoupper($v);
            if (str_contains($u, 'MICRON') || str_contains($u, 'OZ') || str_contains($u, 'µM')) {
                return $v;
            }
            return $v . ' Micron';
        };

        // 1. Header Box Table (JOB NO & Board Type)
        $headerTable = $section->addTable([
            'borderSize' => 12,
            'borderColor' => '000000',
            'cellMarginTop' => Converter::cmToTwip(0.08),
            'cellMarginBottom' => Converter::cmToTwip(0.08),
            'cellMarginLeft' => Converter::cmToTwip(0.15),
            'cellMarginRight' => Converter::cmToTwip(0.15),
        ]);
        $headerRow = $headerTable->addRow(Converter::cmToTwip(0.65));
        $c1 = $headerRow->addCell(5500, ['valign' => VerticalJc::CENTER]);
        $p1 = $c1->addTextRun();
        $p1->addText('JOB NO: ', ['bold' => true, 'size' => 13]);
        $p1->addText($safeText($jobCardData['job_number'] ?? $order->order_number), ['bold' => true, 'size' => 13, 'underline' => 'single']);

        $c2 = $headerRow->addCell(5500, ['valign' => VerticalJc::CENTER]);
        $c2->addText($safeText($jobCardData['job_type'] ?? '1- SIDE'), ['bold' => true, 'size' => 13], ['alignment' => Jc::RIGHT]);

        // 2. Specifications Block A: Dates (3 Columns)
        $tableA = $section->addTable($tableStyle);
        $rowA = $tableA->addRow(Converter::cmToTwip(0.42));
        $rowA->addCell(3666)->addText('Order Date: ' . $safeText($this->formatDateOnly($jobCardData['order_date'] ?? null)), ['bold' => true, 'size' => 8.5]);
        $rowA->addCell(3666)->addText('Launch Date: ' . $safeText($this->formatDateOnly($jobCardData['launch_date'] ?? null)), ['bold' => true, 'size' => 8.5]);
        $rowA->addCell(3668)->addText('Shipping Date: ' . $safeText($this->formatDateOnly($jobCardData['shipping_date'] ?? null)), ['bold' => true, 'size' => 8.5]);

        // 3. Specifications Block B: Quantities & Min Hole
        $tableB = $section->addTable($tableStyle);
        $rowB = $tableB->addRow(Converter::cmToTwip(0.42));
        $rowB->addCell(2200)->addText('ORDER QTY: ' . $safeText($jobCardData['order_qty'] ?? ''), ['bold' => true, 'size' => 8.5]);
        $rowB->addCell(2200)->addText('LAUNCHED: ' . $safeText($jobCardData['launched_qty'] ?? ''), ['bold' => true, 'size' => 8.5]);
        $rowB->addCell(1800)->addText('UPS: ' . $safeText($jobCardData['ups'] ?? ''), ['bold' => true, 'size' => 8.5]);
        $rowB->addCell(2000)->addText('PANELS: ' . $safeText($jobCardData['panels'] ?? ''), ['bold' => true, 'size' => 8.5]);
        $rowB->addCell(2800)->addText('Min.Hole: ' . $safeText($jobCardData['min_hole'] ?? ''), ['bold' => true, 'size' => 8.5]);

        // 4. Specifications Block C: Panel & Cutting Size (2 Equal Columns)
        $tableC = $section->addTable($tableStyle);
        $rowC = $tableC->addRow(Converter::cmToTwip(0.42));
        $rowC->addCell(5500)->addText('PANEL SIZE: ' . $formatMm($jobCardData['panel_size'] ?? ''), ['bold' => true, 'size' => 8.5]);
        $rowC->addCell(5500)->addText('CUTTING SIZE: ' . $formatMm($jobCardData['cutting_size'] ?? ''), ['bold' => true, 'size' => 8.5]);

        // 5. Specifications Block D: Material & Finish Options (4 Equal Columns)
        $tableD = $section->addTable($tableStyle);
        $rowD = $tableD->addRow(Converter::cmToTwip(0.42));
        $rowD->addCell(2750)->addText('Material: ' . $safeText($jobCardData['material'] ?? ''), ['size' => 8.5]);
        $rowD->addCell(2750)->addText('Thick: ' . $formatMm($jobCardData['thickness'] ?? ''), ['size' => 8.5]);
        $rowD->addCell(2750)->addText('Copper Thick: ' . $formatCopper($jobCardData['copper_thickness'] ?? ''), ['size' => 8.5]);
        $rowD->addCell(2750)->addText('Finish: ' . $safeText($jobCardData['finish'] ?? ''), ['size' => 8.5]);

        // 6. Specifications Block E: Mask & Legend Specs (3 Columns)
        $tableE = $section->addTable($tableStyle);
        $rowE = $tableE->addRow(Converter::cmToTwip(0.42));
        $rowE->addCell(3666)->addText('Mask Colour: ' . $safeText($jobCardData['mask_colour'] ?? ''), ['size' => 8.5]);
        $rowE->addCell(3666)->addText('LP Color: ' . $safeText($jobCardData['lp_color'] ?? ''), ['size' => 8.5]);
        $rowE->addCell(3668)->addText('LP Side: ' . $safeText($jobCardData['lp_side'] ?? ''), ['size' => 8.5]);

        // 7. Specifications Block F: Fabrication Options
        $tableF = $section->addTable($tableStyle);
        $rowF = $tableF->addRow(Converter::cmToTwip(0.42));
        if (!empty($jobCardData['is_single_side'])) {
            $rowF->addCell(2750)->addText('Route: ' . $safeText($jobCardData['route'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('V-Cut: ' . $safeText($jobCardData['v_cut'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('Shearing Cut: ' . $safeText($jobCardData['shearing_cut'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('Internal Cutouts: ' . $safeText($jobCardData['internal_cutouts'] ?? ''), ['size' => 8.5]);
        } else {
            $rowF->addCell(2750)->addText('Route: ' . $safeText($jobCardData['route'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('V-Cut: ' . $safeText($jobCardData['v_cut'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('FPT Program: ' . $safeText($jobCardData['fpt_program'] ?? ''), ['size' => 8.5]);
            $rowF->addCell(2750)->addText('2nd stage reqd.?: ' . $safeText($jobCardData['second_stage'] ?? ''), ['size' => 8.5]);
        }

        // 8. Notes Section Table (2 Columns: Production Note 60%, Customer Note 40%)
        $notesTable = $section->addTable($tableStyle);
        $notesRow = $notesTable->addRow(Converter::cmToTwip(1.1));
        $cNotes1 = $notesRow->addCell(6600, ['valign' => VerticalJc::TOP]);
        $cNotes1->addText('Production Note:', ['bold' => true, 'size' => 8.5, 'underline' => 'single']);
        $prodNotes = $safeText($jobCardData['production_note'] ?? "• \n• ");
        foreach (explode("\n", $prodNotes) as $nLine) {
            $cNotes1->addText($safeText($nLine), ['size' => 8.0]);
        }

        $cNotes2 = $notesRow->addCell(4400, ['valign' => VerticalJc::TOP]);
        $cNotes2->addText('Customer Special Note:', ['bold' => true, 'size' => 8.5, 'underline' => 'single']);
        $custNotes = $safeText($jobCardData['customer_note'] ?? '');
        foreach (explode("\n", $custNotes) as $cLine) {
            $cNotes2->addText($safeText($cLine), ['size' => 8.0]);
        }

        // 9. Final Qty & Rejection Summary Table (4 Columns)
        $finalTable = $section->addTable($tableStyle);
        $rowFinalH = $finalTable->addRow(Converter::cmToTwip(0.38));
        $rowFinalH->addCell(2750, ['valign' => VerticalJc::CENTER])->addText('Final Panel Qty.', ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalH->addCell(2750, ['valign' => VerticalJc::CENTER])->addText('Final Board Qty.', ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalH->addCell(2750, ['valign' => VerticalJc::CENTER])->addText('Rejected Board Qty.', ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalH->addCell(2750, ['valign' => VerticalJc::CENTER])->addText('Why Rejected?', ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);

        $rowFinalV = $finalTable->addRow(Converter::cmToTwip(0.38));
        $rowFinalV->addCell(2750, ['valign' => VerticalJc::CENTER])->addText($safeText($jobCardData['final_panel_qty'] ?? ''), ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalV->addCell(2750, ['valign' => VerticalJc::CENTER])->addText($safeText($jobCardData['final_board_qty'] ?? ''), ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalV->addCell(2750, ['valign' => VerticalJc::CENTER])->addText($safeText($jobCardData['rejected_board_qty'] ?? ''), ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);
        $rowFinalV->addCell(2750, ['valign' => VerticalJc::CENTER])->addText($safeText($jobCardData['why_rejected'] ?? ''), ['bold' => true, 'size' => 8.5], ['alignment' => Jc::CENTER]);

        // 10. Manufacturing Process Routing Log Table Header Title
        $pLogTitle = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceBefore' => 60, 'spaceAfter' => 20]);
        $pLogTitle->addText('MANUFACTURING PROCESS ROUTING LOG', ['bold' => true, 'size' => 9.5]);

        $procTable = $section->addTable($tableStyle);
        $headerCellOpts = ['bgColor' => $headerBg, 'valign' => VerticalJc::CENTER];
        $headerTextOpts = ['bold' => true, 'color' => 'FFFFFF', 'size' => 8.0];

        $procTable->addRow(Converter::cmToTwip(0.42));
        $procTable->addCell(2500, $headerCellOpts)->addText('PROCESS', $headerTextOpts, ['alignment' => Jc::LEFT]);
        $procTable->addCell(800, $headerCellOpts)->addText('IN', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1300, $headerCellOpts)->addText('PANEL QTY', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(800, $headerCellOpts)->addText('OUT', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1300, $headerCellOpts)->addText('PANEL QTY', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(800, $headerCellOpts)->addText('Q.C', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(1100, $headerCellOpts)->addText('SIGN', $headerTextOpts, ['alignment' => Jc::CENTER]);
        $procTable->addCell(2400, $headerCellOpts)->addText('REMARK', $headerTextOpts, ['alignment' => Jc::LEFT]);

        $processes = $jobCardData['processes'] ?? [];
        foreach ($processes as $p) {
            $procTable->addRow(Converter::cmToTwip(0.36));
            $procTable->addCell(2500)->addText($safeText($p['process'] ?? ''), ['bold' => true, 'size' => 7.5]);
            $procTable->addCell(800)->addText($safeText($p['in'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1300)->addText($safeText($p['panel_qty_in'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($safeText($p['out'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1300)->addText($safeText($p['panel_qty_out'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(800)->addText($safeText($p['qc'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(1100)->addText($safeText($p['sign'] ?? ''), ['size' => 7.5], ['alignment' => Jc::CENTER]);
            $procTable->addCell(2400)->addText($safeText($p['remark'] ?? ''), ['size' => 7.5]);
        }

        // Extra summary row to match reference Job Card layout
        $procTable->addRow(Converter::cmToTwip(0.36));
        $procTable->addCell(2500)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(800)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(1300)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(800)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(1300)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(800)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(1100)->addText(' ', ['size' => 7.5]);
        $procTable->addCell(2400)->addText(' ', ['size' => 7.5]);

        // 11. Attached Documents & Images (All attached documents active for order)
        try {
            if ($order && $order->id) {
                $attachedDocs = JobCardDocument::where('pcb_order_id', $order->id)
                    ->where('status', 'active')
                    ->orderBy('sort_order', 'asc')
                    ->get();

                foreach ($attachedDocs as $doc) {
                    $ext = strtolower(pathinfo($doc->original_name ?: $doc->file_path, PATHINFO_EXTENSION));
                    $sourceType = (string)$doc->source_type;

                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) || str_contains($sourceType, 'image');
                    $isDocx = in_array($ext, ['doc', 'docx']) || str_contains($sourceType, 'doc');

                    if ($isImage) {
                        $imgPath = storage_path('app/' . $doc->file_path);
                        if (file_exists($imgPath) && filesize($imgPath) > 0) {
                            $section->addPageBreak();
                            $section->addText('ATTACHMENT: ' . $safeText($doc->original_name), ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
                            $section->addTextBreak(1);

                            $section->addImage($imgPath, [
                                'width' => 460,
                                'alignment' => Jc::CENTER,
                            ]);
                        }
                    } else if ($isDocx) {
                        $docPath = storage_path('app/' . $doc->file_path);
                        if (file_exists($docPath) && filesize($docPath) > 0) {
                            $section->addPageBreak();
                            $section->addText('ATTACHMENT: ' . $safeText($doc->original_name), ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
                            $section->addTextBreak(1);

                            try {
                                $docxText = $this->extractTextFromDocx($docPath);
                                if (!empty($docxText)) {
                                    foreach (explode("\n", $docxText) as $line) {
                                        $cleanLine = $safeText($line);
                                        if ($cleanLine !== '') {
                                            $section->addText($cleanLine, ['size' => 9.0]);
                                        }
                                    }
                                } else {
                                    $section->addText('[ Attached Word Document: ' . $safeText($doc->original_name) . ' ]', ['bold' => true, 'size' => 10]);
                                }
                            } catch (\Throwable $e) {
                                $section->addText('[ Attached Word Document: ' . $safeText($doc->original_name) . ' ]', ['bold' => true, 'size' => 10]);
                            }
                        }
                    } else {
                        // PDF Document Attachment
                        $pdfRelative = !empty($doc->converted_pdf_path) ? $doc->converted_pdf_path : $doc->file_path;
                        $pdfPath = storage_path('app/' . $pdfRelative);

                        if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
                            $section->addPageBreak();
                            $section->addText('ATTACHMENT: ' . $safeText($doc->original_name), ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
                            $section->addTextBreak(1);

                            try {
                                $parser = new PdfParser();
                                $pdfParsed = $parser->parseFile($pdfPath);
                                $pages = $pdfParsed->getPages();

                                $pageIndex = 1;
                                foreach ($pages as $page) {
                                    if ($pageIndex > 1) {
                                        $section->addPageBreak();
                                        $section->addText('ATTACHMENT: ' . $safeText($doc->original_name) . " (Page {$pageIndex})", ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);
                                        $section->addTextBreak(1);
                                    }

                                    $text = trim($page->getText());
                                    if (!empty($text)) {
                                        $lines = explode("\n", $text);
                                        foreach ($lines as $line) {
                                            $cleanLine = $safeText($line);
                                            if ($cleanLine !== '') {
                                                $section->addText($cleanLine, ['size' => 9.0]);
                                            }
                                        }
                                    } else {
                                        $section->addText('[ Page ' . $pageIndex . ' contains graphics/drawings. See PDF package for original vector rendering. ]', ['italic' => true, 'color' => '666666', 'size' => 9.0]);
                                    }
                                    $pageIndex++;
                                }
                            } catch (\Throwable $e) {
                                $section->addText('[ Attached PDF Document: ' . $safeText($doc->original_name) . ' ]', ['bold' => true, 'size' => 10]);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently handle attachment embedding errors without breaking main document
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
     * Safely extract plain text from an attached DOCX file without corrupting XML relationships.
     */
    private function extractTextFromDocx(string $docxPath): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($docxPath) === true) {
                if (($index = $zip->locateName('word/document.xml')) !== false) {
                    $data = $zip->getFromIndex($index);
                    $zip->close();
                    $text = strip_tags($data);
                    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$text);
                }
                $zip->close();
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /**
     * Helper to recursively append elements from another section.
     */
    private function appendElementToSection($section, $element): void
    {
        try {
            if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $section->addText($element->getText(), $element->getFontStyle());
            } else if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                $tr = $section->addTextRun();
                foreach ($element->getElements() as $child) {
                    if ($child instanceof \PhpOffice\PhpWord\Element\Text) {
                        $tr->addText($child->getText(), $child->getFontStyle());
                    }
                }
            } else if ($element instanceof \PhpOffice\PhpWord\Element\Title) {
                $section->addTitle($element->getText());
            } else if ($element instanceof \PhpOffice\PhpWord\Element\TextBreak) {
                $section->addTextBreak();
            }
        } catch (\Throwable $e) {
            // Ignore un-supported nested element types gracefully
        }
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


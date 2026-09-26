<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\JobCardDocument;
use App\Services\JobCardDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class JobCardController extends Controller
{
    protected JobCardDocumentService $documentService;

    public function __construct(JobCardDocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * Get or build Job Card data and attached documents for an order.
     */
    public function show($id)
    {
        try {
            $order = $this->findOrder($id);
            $jobCardData = $this->getOrBuildJobCardData($order);
            $documents = $this->documentService->getDocumentsForOrder($order);

            return response()->json([
                'success' => true,
                'data' => array_merge($jobCardData, [
                    'documents' => $documents
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load Job Card data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Job Card data overrides for an order.
     */
    public function save(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id);
            $payload = $request->input('job_card_data') ?: $request->all();

            $jobCardData = $this->cleanAndMergeJobCardData($order, $payload);

            // Save json to metas
            PcbOrderMeta::updateOrCreate(
                ['pcb_order_id' => $order->id, 'meta_key' => 'job_card_data'],
                ['meta_value' => json_encode($jobCardData)]
            );

            // Sync main fields back to order metas where applicable
            $metaKeys = [
                'production_note', 'customer_note', 'cutting_size',
                'final_panel_qty', 'final_board_qty', 'rejected_board_qty', 'why_rejected'
            ];
            foreach ($metaKeys as $key) {
                if (isset($jobCardData[$key])) {
                    PcbOrderMeta::updateOrCreate(
                        ['pcb_order_id' => $order->id, 'meta_key' => $key],
                        ['meta_value' => (string)$jobCardData[$key]]
                    );
                }
            }

            $documents = $this->documentService->getDocumentsForOrder($order);

            return response()->json([
                'success' => true,
                'message' => 'Job Card updated successfully',
                'data' => array_merge($jobCardData, [
                    'documents' => $documents
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save Job Card',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate & stream server-side PDF for an order Job Card (Only Job Card).
     */
    public function generatePdf(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id);
            $inputData = $request->input('job_card_data') ?: $request->all();

            if (!empty($inputData) && is_array($inputData) && (isset($inputData['job_number']) || isset($inputData['processes']))) {
                $jobCardData = $this->cleanAndMergeJobCardData($order, $inputData);
            } else {
                $jobCardData = $this->getOrBuildJobCardData($order);
            }

            $pdfBinary = $this->documentService->generateJobCardPdfBinary($jobCardData);
            $fileName = "JOB_CARD_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $jobCardData['job_number']) . ".pdf";

            return response($pdfBinary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Access-Control-Expose-Headers' => 'Content-Disposition'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Job Card PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List attached documents for an order.
     */
    public function listDocuments($id)
    {
        try {
            $order = $this->findOrder($id);
            $documents = $this->documentService->getDocumentsForOrder($order);

            return response()->json([
                'success' => true,
                'data' => $documents
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list attached documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload an additional document (PDF / DOC / DOCX) for a Job Card.
     */
    public function uploadDocument(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id);

            if (!$request->hasFile('document')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded. Please choose a PDF, DOC, or DOCX document.'
                ], 422);
            }

            $file = $request->file('document');
            $adminId = $request->attributes->get('admin_id') ?: ($request->user()?->id);

            $document = $this->documentService->uploadDocument($order, $file, $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => $document
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Failed to upload document',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete an attached document.
     */
    public function deleteDocument(Request $request, $id, $docId)
    {
        try {
            $order = $this->findOrder($id);
            $adminId = $request->attributes->get('admin_id') ?: ($request->user()?->id);

            $this->documentService->deleteDocument($order, (int)$docId, $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Document removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder attached documents sequence.
     */
    public function reorderDocuments(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id);
            $documents = $request->input('documents', []);

            if (!is_array($documents)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid sequence payload.'
                ], 422);
            }

            $adminId = $request->attributes->get('admin_id') ?: ($request->user()?->id);
            $this->documentService->reorderDocuments($order, $documents, $adminId);

            return response()->json([
                'success' => true,
                'message' => 'Document sequence updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update document sequence',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stream an attached document's PDF for inline preview or download.
     */
    public function streamDocumentFile(Request $request, $id, $docId)
    {
        try {
            $order = $this->findOrder($id);
            $doc = JobCardDocument::where('pcb_order_id', $order->id)
                ->where('id', $docId)
                ->firstOrFail();

            $pdfPath = !empty($doc->converted_pdf_path) ? $doc->converted_pdf_path : $doc->file_path;
            $absolutePath = storage_path('app/' . $pdfPath);

            if (!file_exists($absolutePath)) {
                return response()->json(['success' => false, 'message' => 'File not found on server'], 404);
            }

            $filename = !empty($doc->converted_pdf_name) ? $doc->converted_pdf_name : $doc->original_name;

            return response()->file($absolutePath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Access-Control-Expose-Headers' => 'Content-Disposition'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to stream document file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate & stream single combined PDF (Job Card + attached documents in order).
     */
    public function generateCombinedPdf(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id);
            $inputData = $request->input('job_card_data') ?: [];
            $documentSequence = $request->input('document_sequence') ?: $request->input('document_ids') ?: [];

            if (!empty($inputData) && is_array($inputData) && (isset($inputData['job_number']) || isset($inputData['processes']))) {
                $jobCardData = $this->cleanAndMergeJobCardData($order, $inputData);
            } else {
                $jobCardData = $this->getOrBuildJobCardData($order);
            }

            $adminId = $request->attributes->get('admin_id') ?: ($request->user()?->id);
            $combinedBinary = $this->documentService->generateCombinedPdf($order, $jobCardData, $documentSequence, $adminId);

            $fileName = "JOB_CARD_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $jobCardData['job_number']) . "_COMPLETE.pdf";

            return response($combinedBinary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Access-Control-Expose-Headers' => 'Content-Disposition'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Failed to generate combined Job Card PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to resolve order by ID or order_number.
     */
    private function findOrder($id)
    {
        return PcbOrder::with(['metas'])->where(function ($q) use ($id) {
            if (is_numeric($id)) {
                $q->where('id', $id)->orWhere('order_number', $id);
            } else {
                $q->where('order_number', $id);
            }
        })->firstOrFail();
    }

    /**
     * Build or retrieve stored Job Card data for an order.
     */
    private function getOrBuildJobCardData($order)
    {
        $metaVal = $order->getMeta('job_card_data');
        if (!empty($metaVal)) {
            $decoded = is_array($metaVal) ? $metaVal : json_decode($metaVal, true);
            if (is_array($decoded) && isset($decoded['job_number'])) {
                return $this->cleanAndMergeJobCardData($order, $decoded);
            }
        }

        return $this->cleanAndMergeJobCardData($order, []);
    }

    /**
     * Clean and merge Job Card data against order defaults.
     */
    private function cleanAndMergeJobCardData($order, array $overrides = [])
    {
        $layersStr = (string)$order->getMeta('layers', $order->getMeta('layer', '2'));
        $layersNum = (int)preg_replace('/[^0-9]/', '', $layersStr) ?: 2;

        $productType = strtolower((string)$order->getMeta('product_type', ''));
        $isSingleSide = ($layersNum === 1 || str_contains($productType, 'single') || str_contains($productType, '1-side'));

        $jobType = $isSingleSide ? "1- SIDE" : (($layersNum > 2) ? "{$layersNum} Layers-Layer Board" : "2-Layer Board");

        $createdDate = $order->created_at ? Carbon::parse($order->created_at)->format('d M Y, h:i a') : Carbon::now()->format('d M Y, h:i a');
        $launchDate = $order->launch_date ? Carbon::parse($order->launch_date)->format('d M Y, h:i a') : $createdDate;
        $shippingDate = $order->delivery_date ? Carbon::parse($order->delivery_date)->format('d M Y, h:i a') : Carbon::now()->addDays(7)->format('d M Y, h:i a');

        $orderQty = (string)$order->getMeta('qty', $order->getMeta('quantity', $order->order_qty ?? '120'));
        $launchedQty = (string)$order->getMeta('launched_qty', $order->getMeta('launched', $orderQty));
        $ups = (string)$order->getMeta('ups', '1');
        $panels = (string)$order->getMeta('panels', '1');
        $minHole = (string)$order->getMeta('min_hole', $order->getMeta('min_hole_size', '0.8 MM'));

        $panelSize = (string)$order->getMeta('panel_size', $order->getMeta('dimensions', ''));
        $cuttingSize = (string)$order->getMeta('cutting_size', '');

        $material = (string)$order->getMeta('material', $order->getMeta('base_material', 'FR4'));
        $thickness = (string)$order->getMeta('board_thickness', $order->getMeta('thickness', '1.6 MM'));
        $copperThickness = (string)$order->getMeta('copper_thickness', $order->getMeta('copper_weight', '1 Oz'));
        $finish = (string)$order->getMeta('surface_finish', $order->getMeta('finish', 'HAL Finish'));

        $maskColour = (string)$order->getMeta('pcb_color', $order->getMeta('solder_mask', 'Green'));
        $lpColor = (string)$order->getMeta('legend_color', $order->getMeta('silkscreen', 'White'));
        $lpSide = (string)$order->getMeta('silkscreen_side', $order->getMeta('legend_side', 'Top'));

        $route = (string)$order->getMeta('route', $order->getMeta('routing', 'CNC Routing'));
        $vCut = (string)$order->getMeta('v_cut', 'Yes');
        $fptProgram = (string)$order->getMeta('fpt_program', 'MNF-1 / MNF-2');
        $secondStage = (string)$order->getMeta('second_stage', 'Yes');
        $copperArea = (string)$order->getMeta('copper_area', '');
        $internalCutouts = (string)$order->getMeta('internal_cutouts', 'No');
        $shearingCut = (string)$order->getMeta('shearing_cut', 'Yes');

        $productionNote = (string)$order->getMeta('production_note', "• \n• ");
        $customerNote = (string)$order->getMeta('customer_note', $order->getMeta('special_instructions', ''));

        $finalPanelQty = (string)$order->getMeta('final_panel_qty', '');
        $finalBoardQty = (string)$order->getMeta('final_board_qty', $order->completed_qty ?? '');
        $rejectedBoardQty = (string)$order->getMeta('rejected_board_qty', $order->failed_qty ?? '');
        $whyRejected = (string)$order->getMeta('why_rejected', $order->getMeta('rejection_reason', ''));

        // Standard process lists
        $singleSideProcesses = [
            "CUTTING", "DRILL", "DH Print", "Expose/P&E", "Devloping",
            "ETCHING", "ETCHING QC", "PISM", "HAL", "LP",
            "Manual Cutting", "V-CUT", "Routing", "Final QC with QTY", "Packing"
        ];

        $multiLayerProcesses = [
            "CUTTING", "DRILL", "DH Print", "Exposing", "DEVLOPING QC",
            "PLATING", "PLATING QC", "CAUSTIC", "ETCHING", "ETCH QC",
            "PISM", "HAL", "LP", "ROUT", "V-CUT", "BBT / FPT",
            "FINAL QC", "Packing"
        ];

        $defaultProcList = $isSingleSide ? $singleSideProcesses : $multiLayerProcesses;

        $processes = [];
        $overrideProcMap = [];
        if (isset($overrides['processes']) && is_array($overrides['processes'])) {
            foreach ($overrides['processes'] as $pItem) {
                if (is_array($pItem) && isset($pItem['process'])) {
                    $overrideProcMap[strtoupper(trim($pItem['process']))] = $pItem;
                }
            }
        }

        foreach ($defaultProcList as $pName) {
            $key = strtoupper(trim($pName));
            if (isset($overrideProcMap[$key])) {
                $pData = $overrideProcMap[$key];
                $processes[] = [
                    'process' => $pName,
                    'in' => (string)($pData['in'] ?? ''),
                    'panel_qty_in' => (string)($pData['panel_qty_in'] ?? ''),
                    'out' => (string)($pData['out'] ?? ''),
                    'panel_qty_out' => (string)($pData['panel_qty_out'] ?? ''),
                    'qc' => (string)($pData['qc'] ?? ''),
                    'sign' => (string)($pData['sign'] ?? ''),
                    'remark' => (string)($pData['remark'] ?? ''),
                ];
            } else {
                $processes[] = [
                    'process' => $pName,
                    'in' => '',
                    'panel_qty_in' => '',
                    'out' => '',
                    'panel_qty_out' => '',
                    'qc' => '',
                    'sign' => '',
                    'remark' => '',
                ];
            }
        }

        return [
            'order_id' => $order->id,
            'job_number' => $overrides['job_number'] ?? $order->order_number,
            'job_type' => $overrides['job_type'] ?? $jobType,
            'is_single_side' => $overrides['is_single_side'] ?? $isSingleSide,
            'expose' => isset($overrides['expose']) ? (bool)$overrides['expose'] : true,
            'print_and_etch' => isset($overrides['print_and_etch']) ? (bool)$overrides['print_and_etch'] : false,
            'order_date' => $overrides['order_date'] ?? $createdDate,
            'launch_date' => $overrides['launch_date'] ?? $launchDate,
            'shipping_date' => $overrides['shipping_date'] ?? $shippingDate,
            'order_qty' => $overrides['order_qty'] ?? $orderQty,
            'launched_qty' => $overrides['launched_qty'] ?? $launchedQty,
            'ups' => $overrides['ups'] ?? $ups,
            'panels' => $overrides['panels'] ?? $panels,
            'min_hole' => $overrides['min_hole'] ?? $minHole,
            'panel_size' => $overrides['panel_size'] ?? $panelSize,
            'cutting_size' => $overrides['cutting_size'] ?? $cuttingSize,
            'material' => $overrides['material'] ?? $material,
            'thickness' => $overrides['thickness'] ?? $thickness,
            'copper_thickness' => $overrides['copper_thickness'] ?? $copperThickness,
            'finish' => $overrides['finish'] ?? $finish,
            'mask_colour' => $overrides['mask_colour'] ?? $maskColour,
            'lp_color' => $overrides['lp_color'] ?? $lpColor,
            'lp_side' => $overrides['lp_side'] ?? $lpSide,
            'route' => $overrides['route'] ?? $route,
            'v_cut' => $overrides['v_cut'] ?? $vCut,
            'fpt_program' => $overrides['fpt_program'] ?? $fptProgram,
            'second_stage' => $overrides['second_stage'] ?? $secondStage,
            'copper_area' => $overrides['copper_area'] ?? $copperArea,
            'internal_cutouts' => $overrides['internal_cutouts'] ?? $internalCutouts,
            'shearing_cut' => $overrides['shearing_cut'] ?? $shearingCut,
            'production_note' => $overrides['production_note'] ?? $productionNote,
            'customer_note' => $overrides['customer_note'] ?? $customerNote,
            'final_panel_qty' => $overrides['final_panel_qty'] ?? $finalPanelQty,
            'final_board_qty' => $overrides['final_board_qty'] ?? $finalBoardQty,
            'rejected_board_qty' => $overrides['rejected_board_qty'] ?? $rejectedBoardQty,
            'why_rejected' => $overrides['why_rejected'] ?? $whyRejected,
            'processes' => $processes,
        ];
    }
}

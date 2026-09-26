<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class JobCardController extends Controller
{
    /**
     * Get or build Job Card data for an order.
     */
    public function show($id)
    {
        try {
            $order = PcbOrder::with(['metas'])->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->firstOrFail();

            $jobCardData = $this->getOrBuildJobCardData($order);

            return response()->json([
                'success' => true,
                'data' => $jobCardData
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
            $order = PcbOrder::with(['metas'])->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->firstOrFail();

            $payload = $request->input('job_card_data') ?: $request->all();

            $jobCardData = $this->cleanAndMergeJobCardData($order, $payload);

            // Save json to metas
            PcbOrderMeta::updateOrCreate(
                ['pcb_order_id' => $order->id, 'meta_key' => 'job_card_data'],
                ['meta_value' => json_encode($jobCardData)]
            );

            // Sync main fields back to order metas where applicable
            if (isset($jobCardData['production_note'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'production_note'],
                    ['meta_value' => (string)$jobCardData['production_note']]
                );
            }
            if (isset($jobCardData['customer_note'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'customer_note'],
                    ['meta_value' => (string)$jobCardData['customer_note']]
                );
            }
            if (isset($jobCardData['cutting_size'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'cutting_size'],
                    ['meta_value' => (string)$jobCardData['cutting_size']]
                );
            }
            if (isset($jobCardData['final_panel_qty'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'final_panel_qty'],
                    ['meta_value' => (string)$jobCardData['final_panel_qty']]
                );
            }
            if (isset($jobCardData['final_board_qty'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'final_board_qty'],
                    ['meta_value' => (string)$jobCardData['final_board_qty']]
                );
            }
            if (isset($jobCardData['rejected_board_qty'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'rejected_board_qty'],
                    ['meta_value' => (string)$jobCardData['rejected_board_qty']]
                );
            }
            if (isset($jobCardData['why_rejected'])) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'why_rejected'],
                    ['meta_value' => (string)$jobCardData['why_rejected']]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Job Card updated successfully',
                'data' => $jobCardData
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
     * Generate & stream server-side PDF for an order Job Card.
     */
    public function generatePdf(Request $request, $id)
    {
        try {
            $order = PcbOrder::with(['metas'])->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->firstOrFail();

            $inputData = $request->input('job_card_data') ?: $request->all();

            // If inputData has custom fields, use/merge them; otherwise load from order
            if (!empty($inputData) && is_array($inputData) && (isset($inputData['job_number']) || isset($inputData['processes']))) {
                $jobCardData = $this->cleanAndMergeJobCardData($order, $inputData);
            } else {
                $jobCardData = $this->getOrBuildJobCardData($order);
            }

            $pdf = Pdf::loadView('pdf.job-card', ['data' => $jobCardData]);
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif',
                'dpi' => 150
            ]);

            $fileName = "JOB_CARD_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $jobCardData['job_number']) . ".pdf";

            return response($pdf->output(), 200, [
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

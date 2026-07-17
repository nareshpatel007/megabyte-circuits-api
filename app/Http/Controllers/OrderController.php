<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PcbOrder;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            // Basic PCB Specifications
            'base_material' => 'required|string|max:50',
            'layers' => 'required|integer|min:1|max:16',
            'width' => 'required|numeric|min:0',
            'height' => 'required|numeric|min:0',
            'unit' => 'required|in:mm,inches',
            'qty' => 'required|integer|min:3',
            'product_type' => 'required|string|max:100',
            'different_design' => 'required|integer|min:1|max:4',
            
            // PCB Specifications
            'thickness' => 'required|string|max:20',
            'pcb_color' => 'required|string|max:20',
            'silkscreen' => 'required|string|max:50',
            'material_type' => 'required|string|max:100',
            'surface_finish' => 'required|string|max:50',
            
            // High-spec Options
            'copper_weight' => 'required|string|max:20',
            'via_covering' => 'nullable|string|max:100',
            'via_plating' => 'nullable|string|max:100',
            'min_hole' => 'nullable|string|max:50',
            'tolerance' => 'nullable|string|max:50',
            'confirm_file' => 'nullable|string|max:10',
            'mark_on_pcb' => 'nullable|string|max:100',
            'elec_test' => 'nullable|string|max:100',
            'gold_fingers' => 'nullable|string|max:10',
            'castellated' => 'nullable|string|max:10',
            'edge_plating' => 'nullable|string|max:10',
            'blind_slots' => 'nullable|string|max:10',
            'ul_marking' => 'nullable|string|max:100',
            'humidity' => 'nullable|string|max:10',
            
            // Advanced Options
            'kelvin_test' => 'nullable|string|max:10',
            'paper_between' => 'nullable|string|max:10',
            'appearance_quality' => 'nullable|string|max:100',
            'silkscreen_tech' => 'nullable|string|max:100',
            'inspection_report' => 'nullable|string|max:100',
            'pcb_remark' => 'nullable|string',
            
            // Additional Options
            'assembly_on' => 'nullable|boolean',
            'stencil_on' => 'nullable|boolean',
            'build_time' => 'nullable|string|max:50',
            
            // Customer Information
            'board_name' => 'required|string|max:200',
            'user_mobile' => 'required|string|regex:/^[0-9]{10}$/',
            'user_email' => 'required|email|max:200',
            'gst_number' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:200',
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',
            
            // Pricing Information
            'lead_time_days' => 'required|integer|min:1|max:30',
            'unit_price' => 'required|numeric|min:0',
            'order_value' => 'required|numeric|min:0',
            'delivery_date' => 'required|date',
            'total_area_sqm' => 'required|numeric|min:0',
            
            // File Upload
            'gerber_file' => 'nullable|file|mimes:zip,gz, rar,7z|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle file upload
            $gerberFileUrl = null;
            $gerberFileName = null;
            $gerberFileSize = null;

            if ($request->hasFile('gerber_file')) {
                $file = $request->file('gerber_file');
                $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs('gerber-files', $fileName, 'public');
                
                $gerberFileUrl = Storage::url($filePath);
                $gerberFileName = $file->getClientOriginalName();
                $gerberFileSize = $this->formatFileSize($file->getSize());
            }

            // Generate unique order number
            $orderNumber = 'MB' . strtoupper(Str::random(8)) . date('Ymd');

            // Create the order
            $order = PcbOrder::create([
                // Basic PCB Specifications
                'base_material' => $request->base_material,
                'layers' => $request->layers,
                'width' => $request->width,
                'height' => $request->height,
                'unit' => $request->unit,
                'qty' => $request->qty,
                'product_type' => $request->product_type,
                'different_design' => $request->different_design,
                
                // PCB Specifications
                'thickness' => $request->thickness,
                'pcb_color' => $request->pcb_color,
                'silkscreen' => $request->silkscreen,
                'material_type' => $request->material_type,
                'surface_finish' => $request->surface_finish,
                
                // High-spec Options
                'copper_weight' => $request->copper_weight,
                'via_covering' => $request->via_covering ?? 'Not Specified',
                'via_plating' => $request->via_plating ?? 'Not Specified',
                'min_hole' => $request->min_hole ?? '0.3mm',
                'tolerance' => $request->tolerance ?? 'Regular',
                'confirm_file' => $request->confirm_file ?? 'No',
                'mark_on_pcb' => $request->mark_on_pcb ?? 'Remove Mark',
                'elec_test' => $request->elec_test ?? 'Flying Probe Fully Test',
                'gold_fingers' => $request->gold_fingers ?? 'No',
                'castellated' => $request->castellated ?? 'No',
                'edge_plating' => $request->edge_plating ?? 'No',
                'blind_slots' => $request->blind_slots ?? 'No',
                'ul_marking' => $request->ul_marking ?? 'No',
                'humidity' => $request->humidity ?? 'No',
                
                // Advanced Options
                'kelvin_test' => $request->kelvin_test ?? 'No',
                'paper_between' => $request->paper_between ?? 'No',
                'appearance_quality' => $request->appearance_quality ?? 'IPC Class 2 Standard',
                'silkscreen_tech' => $request->silkscreen_tech ?? 'Ink-jet Printing Silkscreen',
                'inspection_report' => $request->inspection_report ?? 'No',
                'pcb_remark' => $request->pcb_remark,
                
                // Additional Options
                'assembly_on' => $request->assembly_on ?? false,
                'stencil_on' => $request->stencil_on ?? false,
                'build_time' => $request->build_time ?? '2 days',
                
                // Customer Information
                'board_name' => $request->board_name,
                'user_mobile' => $request->user_mobile,
                'user_email' => $request->user_email,
                'gst_number' => $request->gst_number,
                'customer_name' => $request->customer_name,
                'billing_address' => $request->billing_address,
                'shipping_address' => $request->shipping_address,
                
                // Pricing Information
                'lead_time_days' => $request->lead_time_days,
                'unit_price' => $request->unit_price,
                'order_value' => $request->order_value,
                'delivery_date' => $request->delivery_date,
                'total_area_sqm' => $request->total_area_sqm,
                
                // File Upload
                'gerber_file_url' => $gerberFileUrl,
                'gerber_file_name' => $gerberFileName,
                'gerber_file_size' => $gerberFileSize,
                
                // Order Status
                'status' => 'pending',
                'order_number' => $orderNumber,
                
                // Metadata
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order submitted successfully',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total_value' => $order->order_value,
                    'delivery_date' => $order->delivery_date->format('d M Y'),
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit order',
                'error' => $e->getMessage()
            ], 500);
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

    public function index()
    {
        $orders = PcbOrder::orderBy('created_at', 'desc')->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function show($id)
    {
        $order = PcbOrder::findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }
}

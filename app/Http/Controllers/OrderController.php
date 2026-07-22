<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer',
            'board_name' => 'required|string|max:200',
            'user_mobile' => 'required|string',
            'user_email' => 'required|email|max:200',
            'customer_name' => 'nullable|string|max:200',
            'unit_price' => 'nullable|numeric|min:0',
            'order_value' => 'nullable|numeric|min:0',
            'delivery_date' => 'nullable|date',
            'gerber_file' => 'nullable|file|mimes:zip,gz,rar,7z|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find or create PcbUser by email
            $userId = $request->user_id;
            if (!$userId && $request->user_email) {
                $user = \App\Models\PcbUser::where('email', $request->user_email)->first();
                if (!$user) {
                    $user = \App\Models\PcbUser::create([
                        'email' => $request->user_email,
                        'name' => $request->customer_name ?? strtok($request->user_email, '@'),
                        'mobile' => $request->user_mobile,
                        'gst_number' => $request->gst_number ?? null,
                        'status' => 'active',
                    ]);
                } else {
                    // Update mobile or name if missing
                    if (empty($user->mobile) && $request->user_mobile) {
                        $user->mobile = $request->user_mobile;
                        $user->save();
                    }
                }
                $userId = $user->id;
            }

            // Generate unique sequential order number starting from M0001
            $lastOrder = PcbOrder::withTrashed()->orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? ($lastOrder->id + 1) : 1;
            $orderNumber = 'M' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

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

            // Create the main compact order record
            $order = PcbOrder::create([
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'board_name' => $request->board_name,
                'customer_name' => $request->customer_name,
                'user_email' => $request->user_email,
                'user_mobile' => $request->user_mobile,
                'status' => 'pending',
                'unit_price' => $request->unit_price ?? 0,
                'order_value' => $request->order_value ?? 0,
                'delivery_date' => $request->delivery_date,
            ]);

            // Save all additional specification attributes into pcb_order_meta
            $metaData = $request->except([
                'user_id', 'order_number', 'board_name', 'customer_name', 'user_email', 'user_mobile',
                'status', 'unit_price', 'order_value', 'delivery_date', 'gerber_file'
            ]);

            if ($gerberFileUrl) {
                $metaData['gerber_file_url'] = $gerberFileUrl;
                $metaData['gerber_file_name'] = $gerberFileName;
                $metaData['gerber_file_size'] = $gerberFileSize;
            }

            $metaData['ip_address'] = $request->ip();
            $metaData['user_agent'] = $request->userAgent();

            foreach ($metaData as $key => $value) {
                if ($value !== null && $value !== '') {
                    PcbOrderMeta::create([
                        'pcb_order_id' => $order->id,
                        'meta_key' => $key,
                        'meta_value' => is_array($value) ? json_encode($value) : (string)$value,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Order submitted successfully',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total_value' => $order->order_value,
                    'delivery_date' => $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('M d, Y') : null,
                    'board_name' => $order->board_name,
                    'user_email' => $order->user_email,
                    'user_mobile' => $order->user_mobile,
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
        $orders = PcbOrder::with('metas')->orderBy('created_at', 'desc')->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function show($id)
    {
        $order = PcbOrder::with('metas')->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\PcbOrderStatusHistory;

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

    public function index(Request $request)
    {
        $query = PcbOrder::with(['metas', 'statusDetails', 'statusHistories']);

        // Date Range Filtering
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Sorting (Default: delivery_date desc)
        $sortBy = $request->input('sort_by', 'delivery_date');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'delivery_date') {
            // Sort by delivery_date desc, fallback to created_at desc if delivery_date is null
            $query->orderByRaw("COALESCE(delivery_date, created_at) {$sortOrder}");
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $orders = $query->get();
        
        return response()->json([
            'status' => true,
            'data' => $orders
        ]);
    }

    public function show($id)
    {
        $order = PcbOrder::with(['metas', 'statusDetails', 'statusHistories', 'user'])->findOrFail($id);
        
        // Also load internal notes if table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
            $order->notes = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                ->where('pcb_order_notes.pcb_order_id', $id)
                ->select(
                    'pcb_order_notes.*',
                    'admins.name as admin_name',
                    'admins.username as admin_username'
                )
                ->orderBy('pcb_order_notes.created_at', 'desc')
                ->get();
        } else {
            $order->notes = [];
        }

        return response()->json([
            'status' => true,
            'data' => $order
        ]);
    }

    public function update(Request $request, $id)
    {
        try {
            $order = PcbOrder::findOrFail($id);
            $adminId = $request->input('admin_id') ?: $request->attributes->get('admin_id');
            $remark = $request->input('remark', null);
            
            if ($request->has('status')) {
                $order->status = $request->status;
            }

            if ($request->has('status_id')) {
                $order->status_id = $request->status_id;
            }

            if ($request->has('delivery_date')) {
                $order->delivery_date = $request->delivery_date;
            }

            $order->save();

            // Create status change history log with logged-in admin ID & timestamp
            if ($request->has('status')) {
                PcbOrderStatusHistory::create([
                    'pcb_order_id' => $order->id,
                    'admin_id' => $adminId ?: 1,
                    'status_name' => $request->status,
                    'remark' => $remark,
                    'created_at' => now()
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully',
                'data' => $order->load(['metas', 'statusDetails', 'statusHistories'])
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Order Internal Notes
    public function getNotes($id)
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                return response()->json(['status' => true, 'data' => []]);
            }

            $notes = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                ->where('pcb_order_notes.pcb_order_id', $id)
                ->select(
                    'pcb_order_notes.*',
                    'admins.name as admin_name',
                    'admins.username as admin_username'
                )
                ->orderBy('pcb_order_notes.created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $notes
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function addNote(Request $request, $id)
    {
        try {
            $noteText = $request->input('note');
            $adminId = $request->input('admin_id') ?: $request->attributes->get('admin_id');

            if (empty($noteText)) {
                return response()->json(['status' => false, 'message' => 'Note content cannot be empty.'], 400);
            }

            $noteId = \Illuminate\Support\Facades\DB::table('pcb_order_notes')->insertGetId([
                'pcb_order_id' => $id,
                'admin_id' => $adminId ?: 1,
                'note' => $noteText,
                'is_internal' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Note added successfully.',
                'data' => ['id' => $noteId]
            ], 201);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deleteNote($noteId)
    {
        try {
            \Illuminate\Support\Facades\DB::table('pcb_order_notes')->where('id', $noteId)->delete();
            return response()->json(['status' => true, 'message' => 'Note deleted.']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
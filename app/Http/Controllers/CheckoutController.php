<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    private $razorpayKey;
    private $razorpaySecret;

    public function __construct()
    {
        $mode = env('RAZORPAY_MODE', config('services.razorpay.mode', 'sandbox'));
        $isLive = in_array(strtolower((string)$mode), ['live', 'production']);

        if ($isLive) {
            $this->razorpayKey = env('RAZORPAY_LIVE_KEY_ID') ?: config('services.razorpay.key_id');
            $this->razorpaySecret = env('RAZORPAY_LIVE_KEY_SECRET') ?: config('services.razorpay.key_secret');
        } else {
            $this->razorpayKey = env('RAZORPAY_TEST_KEY_ID') ?: (config('services.razorpay.key_id') ?: 'rzp_test_SQxqJOMmeLZK9n');
            $this->razorpaySecret = env('RAZORPAY_TEST_KEY_SECRET') ?: (config('services.razorpay.key_secret') ?: '9Y3M6fGuUUp7zxevkNkqeMDV');
        }
    }

    // Get active (non-soft-deleted) saved addresses for user
    public function getAddresses(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => true, 'addresses' => []]);
            }

            $addresses = DB::table('user_addresses')
                ->where('user_id', $userId)
                ->whereNull('deleted_at') // Filter out soft-deleted addresses
                ->orderBy('is_default', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            return response()->json(['status' => true, 'addresses' => $addresses]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Get list of states
    public function getStates()
    {
        try {
            $states = DB::table('states')
                ->select('id', 'code', 'name')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $states
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }


    // Save a new address or update existing via SoftDelete preservation
    public function saveAddress(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $userId = $input['user_id'] ?? null;
            $editAddressId = $input['id'] ?? null;

            // If updating an existing address ID, soft-delete the old record to preserve historical orders
            if ($editAddressId) {
                DB::table('user_addresses')
                    ->where('id', $editAddressId)
                    ->update(['deleted_at' => date('Y-m-d H:i:s')]);
            }

            $existingCount = $userId ? DB::table('user_addresses')->where('user_id', $userId)->whereNull('deleted_at')->count() : 0;
            $isDefault = $existingCount === 0;

            // Insert new address record
            $addressId = DB::table('user_addresses')->insertGetId([
                'user_id' => $userId,
                'address_type' => $input['address_type'] ?? 'shipping',
                'customer_type' => $input['customer_type'] ?? 'individual',
                'company_name' => $input['company_name'] ?? null,
                'first_name' => $input['first_name'] ?? '',
                'last_name' => $input['last_name'] ?? '',
                'country' => 'India',
                'state' => $input['state'] ?? '',
                'city' => $input['city'] ?? '',
                'street_address' => $input['street_address'] ?? '',
                'building_no' => $input['building_no'] ?? null,
                'postal_code' => $input['postal_code'] ?? '',
                'mobile' => $input['mobile'] ?? '',
                'is_default' => $isDefault,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $savedAddress = DB::table('user_addresses')->where('id', $addressId)->first();

            return response()->json([
                'status' => true,
                'message' => 'Address saved successfully',
                'address' => $savedAddress
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Soft-delete an address
    public function deleteAddress(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $id = $input['id'] ?? null;
            $userId = $input['user_id'] ?? null;

            if (!$id || !$userId) {
                return response()->json(['status' => false, 'message' => 'Address ID and User ID are required'], 400);
            }

            DB::table('user_addresses')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update(['deleted_at' => date('Y-m-d H:i:s')]);

            return response()->json(['status' => true, 'message' => 'Address soft-deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Create Razorpay Order
    public function createRazorpayOrder(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $amount = isset($input['amount']) ? (float) preg_replace('/^0+(?=\d)/', '', (string)$input['amount']) : 0; // Amount in INR
            $currency = $input['currency'] ?? 'INR';

            if ($amount <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid order amount'
                ], 400);
            }

            $amountInPaise = (int) round($amount * 100);

            // Call Razorpay API to create order
            $response = Http::withBasicAuth($this->razorpayKey, $this->razorpaySecret)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $amountInPaise,
                    'currency' => $currency,
                    'receipt' => 'rcpt_' . time() . '_' . rand(100, 999),
                    'payment_capture' => 1
                ]);

            if ($response->successful()) {
                $razorpayOrder = $response->json();

                // Audit log transaction initiation
                DB::table('payment_transactions')->insert([
                    'user_id' => $input['user_id'] ?? null,
                    'transaction_number' => 'TXN_' . strtoupper(Str::random(10)),
                    'razorpay_order_id' => $razorpayOrder['id'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'initiated',
                    'payload' => json_encode(['razorpay_order' => $razorpayOrder]),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $logoPath = public_path('images/logo.png');
                $companyLogo = url('/images/logo.png');
                if (file_exists($logoPath)) {
                    $type = pathinfo($logoPath, PATHINFO_EXTENSION);
                    $data = file_get_contents($logoPath);
                    $companyLogo = 'data:image/' . ($type === 'svg' ? 'svg+xml' : $type) . ';base64,' . base64_encode($data);
                }

                return response()->json([
                    'status' => true,
                    'key' => $this->razorpayKey,
                    'order_id' => $razorpayOrder['id'],
                    'amount' => $amountInPaise,
                    'currency' => $currency,
                    'company_name' => config('app.name', 'Megabyte Circuit'),
                    'company_logo' => $companyLogo
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to create Razorpay order',
                    'error' => $response->json()
                ], 500);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Razorpay order creation error: ' . $th->getMessage()
            ], 500);
        }
    }

    // Verify Payment & Create Separate Orders Linked to Pending Status & Gerber File ID
    public function verifyPaymentAndCreateOrders(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $razorpayPaymentId = $input['razorpay_payment_id'] ?? null;
            $razorpayOrderId = $input['razorpay_order_id'] ?? null;
            $razorpaySignature = $input['razorpay_signature'] ?? null;
            
            $userId = $input['user_id'] ?? null;
            $shippingAddressId = $input['shipping_address_id'] ?? ($input['address_id'] ?? null);
            $billingAddressId = $input['billing_address_id'] ?? $shippingAddressId;
            $items = $input['items'] ?? [];

            if (empty($items) || !is_array($items)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No items found in order payload'
                ], 400);
            }

            // Verify signature if provided
            if ($razorpayOrderId && $razorpayPaymentId && $razorpaySignature) {
                $generatedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $this->razorpaySecret);
                if ($generatedSignature !== $razorpaySignature) {
                    // Record failed transaction log
                    DB::table('payment_transactions')->insert([
                        'user_id' => $userId,
                        'transaction_number' => 'TXN_FAIL_' . time(),
                        'razorpay_payment_id' => $razorpayPaymentId,
                        'razorpay_order_id' => $razorpayOrderId,
                        'razorpay_signature' => $razorpaySignature,
                        'amount' => $input['total_amount'] ?? 0,
                        'status' => 'failed',
                        'error_details' => 'Signature verification failed',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    return response()->json([
                        'status' => false,
                        'message' => 'Payment verification signature failed'
                    ], 400);
                }
            }

            // Fetch Pending status record ID
            $pendingStatus = DB::table('pcb_order_statuses')->where('name', 'Pending')->first();
            $statusId = $pendingStatus ? $pendingStatus->id : 1;

            DB::beginTransaction();

            // 1. Audit Log Payment Transaction Record
            $totalAmount = $input['total_amount'] ?? array_sum(array_column($items, 'price'));
            $transactionNumber = 'TXN_' . strtoupper(Str::random(10));
            
            $transactionId = DB::table('payment_transactions')->insertGetId([
                'user_id' => $userId,
                'transaction_number' => $transactionNumber,
                'razorpay_payment_id' => $razorpayPaymentId,
                'razorpay_order_id' => $razorpayOrderId,
                'razorpay_signature' => $razorpaySignature,
                'amount' => $totalAmount,
                'currency' => 'INR',
                'status' => 'success',
                'payment_method' => $input['payment_method'] ?? 'Razorpay',
                'payload' => json_encode([
                    'shipping_address_id' => $shippingAddressId,
                    'billing_address_id' => $billingAddressId,
                    'items_count' => count($items),
                    'payment_id' => $razorpayPaymentId
                ]),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 2. Create SEPARATE Orders in pcb_orders linked STRICTLY via IDs (status_id = Pending, gerber_file_id)
            $createdOrders = [];

            foreach ($items as $index => $item) {
                $parentOrderNumber = $item['parent_order_number'] ?? $item['repeat_parent'] ?? null;

                if (!empty($parentOrderNumber)) {
                    // Repeat Order: Find count of existing repeats for this parent (e.g. M00001-1, M00001-2...)
                    $repeatCount = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', $parentOrderNumber . '-%')
                        ->count();
                    $nextSuffix = $repeatCount + 1;
                    $orderNumber = $parentOrderNumber . '-' . $nextSuffix;
                } else {
                    // Standard Order: Generate M00001, M00002... (+1 of last generated order number)
                    $lastOrder = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', 'M%')
                        ->where('order_number', 'NOT LIKE', '%-%')
                        ->orderBy('id', 'desc')
                        ->first();

                    $nextNum = 1;
                    if ($lastOrder && !empty($lastOrder->order_number)) {
                        $num = (int) preg_replace('/[^0-9]/', '', $lastOrder->order_number);
                        $nextNum = $num > 0 ? ($num + 1) : ((DB::table('pcb_orders')->max('id') ?? 0) + 1);
                    } else {
                        $nextNum = (DB::table('pcb_orders')->max('id') ?? 0) + 1;
                    }
                    $orderNumber = 'M' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
                }

                $boardName = $item['gerberFileName'] ?? $item['boardName'] ?? ($item['productType'] === 'stencil' ? 'SMT Stencil' : 'Standard PCB');
                $itemPrice = $item['price'] ?? 0;
                $itemQty = $item['qty'] ?? 1;
                $unitPrice = $itemQty > 0 ? round($itemPrice / $itemQty, 2) : $itemPrice;

                // Resolve or Create Gerber File Entry
                $gerberFileId = $item['gerber_file_id'] ?? null;
                $previewData = $item['gerberPreview'] ?? $item['preview_data'] ?? null;

                if (!$gerberFileId && !empty($boardName)) {
                    $existingGerber = DB::table('gerber_files')->where('original_name', $boardName)->latest()->first();
                    if ($existingGerber) {
                        $gerberFileId = $existingGerber->id;
                        if ($previewData && empty($existingGerber->preview_data)) {
                            DB::table('gerber_files')->where('id', $gerberFileId)->update(['preview_data' => $previewData]);
                        }
                    } else {
                        $gerberFileId = DB::table('gerber_files')->insertGetId([
                            'user_id' => $userId,
                            'original_name' => $boardName,
                            'file_name' => $boardName,
                            'board_name' => pathinfo($boardName, PATHINFO_FILENAME),
                            'preview_data' => $previewData,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                } else if ($gerberFileId && $previewData) {
                    DB::table('gerber_files')->where('id', $gerberFileId)->whereNull('preview_data')->update(['preview_data' => $previewData]);
                }

                // Update user_id in gerber_files table after order is placed
                if ($gerberFileId && $userId) {
                    $updateData = ['user_id' => $userId, 'updated_at' => date('Y-m-d H:i:s')];
                    if ($previewData) {
                        $updateData['preview_data'] = $previewData;
                    }
                    DB::table('gerber_files')
                        ->where('id', $gerberFileId)
                        ->update($updateData);
                }

                // Insert into pcb_orders storing ONLY foreign ID references (No board_name, status_id = Pending)
                $orderId = DB::table('pcb_orders')->insertGetId([
                    'order_number' => $orderNumber,
                    'user_id' => $userId,
                    'transaction_id' => $transactionId,
                    'shipping_address_id' => $shippingAddressId,
                    'billing_address_id' => $billingAddressId,
                    'status_id' => $statusId, // Links to Pending in pcb_order_statuses
                    'gerber_file_id' => $gerberFileId, // Links to gerber_files record
                    'unit_price' => $unitPrice,
                    'order_value' => $itemPrice,
                    'delivery_date' => date('Y-m-d', strtotime('+5 days')),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Store specifications in pcb_order_meta
                $productType = $item['productType'] ?? 'pcb';

                if ($productType === 'part') {
                    $metaFields = [
                        'product_type' => 'part',
                        'part_number' => $item['partNumber'] ?? $boardName,
                        'description' => $item['description'] ?? '',
                        'quantity' => $itemQty,
                        'transaction_number' => $transactionNumber,
                        'parent_order_number' => $parentOrderNumber,
                    ];
                } else {
                    $metaFields = [
                        'board_name' => $boardName,
                        'product_type' => $productType,
                        'gerber_file_name' => $item['gerberFileName'] ?? '',
                        'pcb_color' => $item['pcbColor'] ?? 'Green',
                        'layers' => $item['layers'] ?? '2',
                        'dimensions' => $item['dimensions'] ?? '',
                        'quantity' => $itemQty,
                        'build_time' => $item['buildTime'] ?? '3-4 days',
                        'thickness' => $item['thickness'] ?? '1.6mm',
                        'surface_finish' => $item['surfaceFinish'] ?? 'HASL(Leaded)',
                        'transaction_number' => $transactionNumber,
                        'parent_order_number' => $parentOrderNumber,
                        'preview_data' => $previewData
                    ];
                }

                foreach ($metaFields as $k => $v) {
                    DB::table('pcb_order_meta')->insert([
                        'pcb_order_id' => $orderId,
                        'meta_key' => $k,
                        'meta_value' => is_array($v) ? json_encode($v) : (string)$v,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }

                // Maintain Order Activity Audit Logs in pcb_order_logs
                $now = date('Y-m-d H:i:s');
                $logsToInsert = [
                    [
                        'pcb_order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'user_id' => $userId,
                        'status' => 'Order Placed',
                        'action' => 'Order Created',
                        'description' => "Order {$orderNumber} successfully created for board '{$boardName}'.",
                        'created_at' => $now,
                        'updated_at' => $now
                    ],
                    [
                        'pcb_order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'user_id' => $userId,
                        'status' => 'Payment Verified',
                        'action' => 'Payment Received',
                        'description' => "Payment of ₹" . number_format($itemPrice, 2) . " verified via Razorpay ID '{$razorpayPaymentId}' (Txn Ref: {$transactionNumber}).",
                        'created_at' => date('Y-m-d H:i:s', strtotime('+1 second')),
                        'updated_at' => date('Y-m-d H:i:s', strtotime('+1 second'))
                    ]
                ];

                DB::table('pcb_order_logs')->insert($logsToInsert);

                $createdOrders[] = [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'board_name' => $boardName,
                    'status' => 'Pending',
                    'price' => $itemPrice
                ];
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment verified and orders created successfully!',
                'orders' => $createdOrders,
                'transaction_id' => $transactionId,
                'transaction_number' => $transactionNumber
            ]);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Order creation failed: ' . $th->getMessage()
            ], 500);
        }
    }
}

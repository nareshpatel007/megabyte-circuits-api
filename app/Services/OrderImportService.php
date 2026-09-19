<?php

namespace App\Services;

use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\PcbOrderStatusHistory;
use App\Models\PcbUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OrderImportService
{
    /**
     * Map of expected manufacturer columns and their aliases
     */
    protected static array $headerAliases = [
        'order_date'        => ['order date', 'orderdate', 'date'],
        'launch_date'       => ['launch date', 'launchdate'],
        'delivery_date'     => ['delivery date', 'deliverydate', 'delivery'],
        'quote_number'      => ['q# no.', 'q# no', 'q#', 'quote no', 'quote number', 'q no'],
        'c_g'               => ['c/g', 'cg', 'c / g'],
        'tool'              => ['tool', 'tool no', 'tool_number'],
        'combo'             => ['combo', 'combo no'],
        'customer_name'     => ['customer name', 'customer', 'client name'],
        'layer'             => ['layer', 'layers'],
        'mask'              => ['mask', 'solder mask', 'pcb color'],
        'p_n'               => ['p/n', 'pn', 'part number', 'board name'],
        'production_noted'  => ['production noted', 'production note', 'production notes', 'notes'],
        'qty'               => ['qty', 'quantity', 'order qty'],
        'launch_qty'        => ['launch', 'launch qty', 'launched qty'],
        'panel_qty'         => ['panel', 'panels', 'panel qty'],
        'ups'               => ['ups'],
        'final_qty'         => ['final qty', 'final quantity', 'completed qty'],
        'status'            => ['status', 'order status'],
        'bill_number'       => ['bill number', 'bill no', 'invoice number'],
    ];

    /**
     * Expected primary columns in exact manufacturer order
     */
    public static array $expectedColumns = [
        'Order Date',
        'Launch Date',
        'Delivery date',
        'Q# No.',
        'C/G',
        'Tool',
        'Combo',
        'Customer name',
        'Layer',
        'Mask',
        'P/N',
        'Production noted',
        'Qty',
        'Launch',
        'Panel',
        'ups',
        'Final qty',
        'status',
        'Bill number'
    ];

    /**
     * Generate a downloadable sample Excel sheet in exact manufacturer format
     */
    public function generateSampleSheet(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $headers = static::$expectedColumns;

        // Write Headers to Row 1
        foreach ($headers as $colIdx => $headerText) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue("{$colLetter}1", $headerText);
        }

        // Apply Header Styling
        $sheet->getStyle('A1:S1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => '000000'],
                'size'  => 11,
                'name'  => 'Calibri',
            ],
            'alignment' => [
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'       => ['rgb' => 'B0B0B0'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Dummy Sample Rows for User Guidance
        $sampleRows = [
            ['7/6/2026', '7/8/2026', '7/18/2026', '999', 'GST', 'M4665', 'J0520', 'Sample Electronics Pvt Ltd', 2, 'Green', 'PCB-SAMPLE-MAIN-BOARD', '3 Day Turn', 100, 120, 20, 6, 120, 'move', '101'],
            ['7/7/2026', '7/9/2026', '7/20/2026', '1002', 'Cash', 'M4666', 'J0521', 'Demo Tech Solutions', 1, 'Red', 'POWER-SUPPLY-V2', '', 50, 60, 10, 6, 60, 'move', '102'],
        ];

        foreach ($sampleRows as $rowIdx => $rowValues) {
            $r = $rowIdx + 2;
            foreach ($rowValues as $colIdx => $value) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$colLetter}{$r}", $value);
            }
        }

        foreach (range(1, 19) as $colIdx) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Normalize customer name for strict, clean comparison:
     * - Trims leading/trailing whitespace
     * - Collapses multiple internal spaces into a single space
     * - Converts string to lowercase
     */
    protected function normalizeCustomerName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $trimmed = trim($name);
        $collapsed = preg_replace('/\s+/', ' ', $trimmed);
        return strtolower($collapsed);
    }

    /**
     * Build an in-memory lookup cache of existing customers in database
     */
    protected function buildCustomerCache(): array
    {
        $existingUsers = PcbUser::select('id', 'name', 'company_name')->get();
        $customerCache = [];
        foreach ($existingUsers as $u) {
            if (!empty($u->name)) {
                $norm = $this->normalizeCustomerName($u->name);
                if ($norm !== '' && !isset($customerCache[$norm])) {
                    $customerCache[$norm] = [
                        'id'     => $u->id,
                        'name'   => $u->name,
                        'is_new' => false,
                    ];
                }
            }
            if (!empty($u->company_name)) {
                $norm = $this->normalizeCustomerName($u->company_name);
                if ($norm !== '' && !isset($customerCache[$norm])) {
                    $customerCache[$norm] = [
                        'id'     => $u->id,
                        'name'   => $u->company_name,
                        'is_new' => false,
                    ];
                }
            }
        }
        return $customerCache;
    }

    /**
     * Preview an Excel file before importing
     */
    public function previewImport(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if (empty($allRows) || count($allRows) < 1) {
            return [
                'success' => false,
                'message' => 'Spreadsheet is empty.',
            ];
        }

        // Read Header Row (Row 0 of array)
        $rawHeaders = $allRows[0];
        $headerMap = $this->mapHeaders($rawHeaders);

        if (!empty($headerMap['missing_headers'])) {
            return [
                'success' => false,
                'message' => 'Required manufacturer columns are missing: ' . implode(', ', $headerMap['missing_headers']),
                'missing_headers' => $headerMap['missing_headers'],
                'found_headers' => array_values($headerMap['column_index_map']),
            ];
        }

        $colIndices = $headerMap['column_index_map'];

        $validRows = [];
        $invalidRows = [];
        $duplicateRows = [];
        $previewItems = [];

        // Pre-fetch existing order numbers to identify duplicates efficiently
        $existingOrderNumbers = PcbOrder::pluck('order_number')->filter()->toArray();
        $existingOrderMap = array_fill_keys(array_map('strtolower', $existingOrderNumbers), true);

        // Build Customer Cache for matching
        $customerCache = $this->buildCustomerCache();
        $existingCustomersUsedCount = 0;
        $virtualCustomerMap = []; // norm => info

        // Process data rows starting from row index 1 (Excel row 2)
        $totalRowsInSheet = count($allRows);
        for ($i = 1; $i < $totalRowsInSheet; $i++) {
            $rowData = $allRows[$i];
            $excelRowNumber = $i + 1;
            
            // Skip entirely blank rows
            if ($this->isRowEmpty($rowData)) {
                continue;
            }

            $extracted = $this->extractRowData($rowData, $colIndices);
            $validation = $this->validateRow($extracted, $excelRowNumber);

            $extracted['row_number'] = $excelRowNumber;
            $extracted['is_valid'] = $validation['valid'];
            $extracted['errors'] = $validation['errors'];

            // Customer matching resolution for preview
            $rawCustomerName = trim((string)($extracted['customer_name'] ?? ''));
            $normCustomer = $this->normalizeCustomerName($rawCustomerName);

            $customerAction = '';
            $resolvedCustomerId = null;
            $isNewCustomer = false;

            if ($normCustomer === '') {
                $customerAction = 'Customer name is required';
            } elseif (isset($customerCache[$normCustomer])) {
                $resolvedCustomerId = $customerCache[$normCustomer]['id'];
                $customerAction = "Existing customer #{$resolvedCustomerId}";
                $existingCustomersUsedCount++;
            } elseif (isset($virtualCustomerMap[$normCustomer])) {
                $customerAction = "Use new customer ({$virtualCustomerMap[$normCustomer]['name']})";
            } else {
                $cleanCustName = preg_replace('/\s+/', ' ', $rawCustomerName);
                $virtualCustomerMap[$normCustomer] = [
                    'name' => $cleanCustName,
                ];
                $customerAction = "Create new customer";
                $isNewCustomer = true;
            }

            $extracted['customer_action'] = $customerAction;
            $extracted['resolved_customer_id'] = $resolvedCustomerId;
            $extracted['is_new_customer'] = $isNewCustomer;

            // Duplicate detection: match Tool or order_number
            $toolVal = trim($extracted['tool'] ?? '');
            $isDuplicate = false;
            $matchedOrderNumber = null;

            if ($toolVal !== '' && isset($existingOrderMap[strtolower($toolVal)])) {
                $isDuplicate = true;
                $matchedOrderNumber = $toolVal;
            }

            $extracted['is_duplicate'] = $isDuplicate;
            $extracted['matched_order_number'] = $matchedOrderNumber;

            if ($validation['valid']) {
                $validRows[] = $extracted;
                if ($isDuplicate) {
                    $duplicateRows[] = $extracted;
                }
            } else {
                $invalidRows[] = $extracted;
            }

            // Limit preview items returned to UI for fast rendering
            if (count($previewItems) < 100) {
                $previewItems[] = $extracted;
            }
        }

        $totalCount = count($validRows) + count($invalidRows);

        return [
            'success' => true,
            'summary' => [
                'total_rows'              => $totalCount,
                'valid_rows'              => count($validRows),
                'invalid_rows'            => count($invalidRows),
                'duplicate_rows'          => count($duplicateRows),
                'new_rows'                => count($validRows) - count($duplicateRows),
                'existing_customers_used' => $existingCustomersUsedCount,
                'new_customers_created'   => count($virtualCustomerMap),
            ],
            'preview_items' => $previewItems,
            'invalid_rows'  => array_map(function ($r) {
                return [
                    'row'    => $r['row_number'],
                    'errors' => $r['errors'],
                ];
            }, $invalidRows),
        ];
    }

    /**
     * Execute full import with chosen duplicate strategy ('skip', 'update', or 'create_new')
     */
    public function executeImport(string $filePath, string $duplicateAction = 'skip'): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if (empty($allRows) || count($allRows) < 1) {
            return [
                'success' => false,
                'message' => 'Spreadsheet is empty.',
            ];
        }

        $rawHeaders = $allRows[0];
        $headerMap = $this->mapHeaders($rawHeaders);

        if (!empty($headerMap['missing_headers'])) {
            return [
                'success' => false,
                'message' => 'Missing required headers: ' . implode(', ', $headerMap['missing_headers']),
            ];
        }

        $colIndices = $headerMap['column_index_map'];

        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $failedRows = [];

        try {
            // Pre-fetch existing order numbers to avoid DB locks during row iterations
            $existingOrders = PcbOrder::select('id', 'order_number')->get();
            $existingOrderMap = [];
            foreach ($existingOrders as $eo) {
                if (!empty($eo->order_number)) {
                    $existingOrderMap[strtolower($eo->order_number)] = $eo->id;
                }
            }

            // Build Customer Cache for matching
            $customerCache = $this->buildCustomerCache();
            $existingCustomersUsedCount = 0;
            $newCustomersCreatedCount = 0;
            $usedExistingCustomerIds = [];

            // Get highest numeric order number for sequential auto-generation if needed
            $lastOrder = PcbOrder::withTrashed()
                ->where('order_number', 'LIKE', 'M%')
                ->where('order_number', 'NOT LIKE', '%-%')
                ->orderBy('id', 'desc')
                ->first();

            $nextNumericId = 1000;
            if ($lastOrder && !empty($lastOrder->order_number)) {
                $num = (int) preg_replace('/[^0-9]/', '', $lastOrder->order_number);
                if ($num > 0) {
                    $nextNumericId = $num;
                }
            }

            $batchSize = 250;
            $rowCounter = 0;
            $totalRowsInSheet = count($allRows);

            DB::beginTransaction();

            for ($i = 1; $i < $totalRowsInSheet; $i++) {
                $rowData = $allRows[$i];
                $excelRowNumber = $i + 1;

                if ($this->isRowEmpty($rowData)) {
                    continue;
                }

                $extracted = $this->extractRowData($rowData, $colIndices);
                $validation = $this->validateRow($extracted, $excelRowNumber);

                if (!$validation['valid']) {
                    $failedCount++;
                    $failedRows[] = [
                        'row'    => $excelRowNumber,
                        'errors' => $validation['errors'],
                    ];
                    continue;
                }

                // Customer resolution & creation logic
                $rawCustomerName = trim((string)($extracted['customer_name'] ?? ''));
                $normCustomer = $this->normalizeCustomerName($rawCustomerName);
                $resolvedUserId = null;

                if ($normCustomer !== '') {
                    if (isset($customerCache[$normCustomer])) {
                        $resolvedUserId = $customerCache[$normCustomer]['id'];
                        if (!$customerCache[$normCustomer]['is_new']) {
                            if (!isset($usedExistingCustomerIds[$resolvedUserId])) {
                                $usedExistingCustomerIds[$resolvedUserId] = true;
                                $existingCustomersUsedCount++;
                            }
                        }
                    } else {
                        // Create brand new customer
                        $cleanCustName = preg_replace('/\s+/', ' ', $rawCustomerName);
                        $slug = \Illuminate\Support\Str::slug($cleanCustName);
                        $uniqueEmail = 'customer_' . ($slug ?: 'user') . '_' . substr(md5(strtolower($cleanCustName)), 0, 6) . '@import.local';

                        $newCustomer = PcbUser::create([
                            'name'         => $cleanCustName,
                            'company_name' => $cleanCustName,
                            'email'        => $uniqueEmail,
                            'status'       => 'active',
                        ]);

                        $resolvedUserId = $newCustomer->id;
                        $customerCache[$normCustomer] = [
                            'id'     => $resolvedUserId,
                            'name'   => $cleanCustName,
                            'is_new' => true,
                        ];
                        $newCustomersCreatedCount++;
                    }
                }

                // Check duplicate order using memory map
                $toolVal = trim($extracted['tool'] ?? '');
                $toolValLower = strtolower($toolVal);
                $existingOrderId = ($toolValLower !== '' && isset($existingOrderMap[$toolValLower])) ? $existingOrderMap[$toolValLower] : null;

                if ($existingOrderId && $duplicateAction !== 'create_new') {
                    if ($duplicateAction === 'skip') {
                        $skippedCount++;
                        continue;
                    } elseif ($duplicateAction === 'update') {
                        $existingOrder = PcbOrder::find($existingOrderId);
                        if ($existingOrder) {
                            $this->updatePcbOrderRecord($existingOrder, $extracted, $resolvedUserId);
                            $updatedCount++;
                        }
                    }
                } else {
                    // Create new order record
                    $orderNumber = $toolVal;
                    if (empty($orderNumber) || ($existingOrderId && $duplicateAction === 'create_new')) {
                        $nextNumericId++;
                        $orderNumber = 'M' . str_pad($nextNumericId, 4, '0', STR_PAD_LEFT);
                    }

                    $newOrder = $this->createPcbOrderRecord($orderNumber, $extracted, $resolvedUserId);
                    $existingOrderMap[strtolower($orderNumber)] = $newOrder->id;
                    $importedCount++;
                }

                $rowCounter++;
                if ($rowCounter % $batchSize === 0) {
                    DB::commit();
                    DB::beginTransaction();
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Import completed successfully.',
                'summary' => [
                    'total'                   => $importedCount + $updatedCount + $skippedCount + $failedCount,
                    'imported'                => $importedCount,
                    'updated'                 => $updatedCount,
                    'skipped'                 => $skippedCount,
                    'failed'                  => $failedCount,
                    'existing_customers_used' => $existingCustomersUsedCount,
                    'new_customers_created'   => $newCustomersCreatedCount,
                ],
                'failed_rows' => $failedRows,
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Map raw header columns to normalized keys
     */
    protected function mapHeaders(array $rawHeaders): array
    {
        $colIndexMap = [];
        $foundKeys = [];

        foreach ($rawHeaders as $idx => $rawHeader) {
            $cleaned = strtolower(trim((string)$rawHeader));
            if ($cleaned === '') continue;

            foreach (static::$headerAliases as $key => $aliases) {
                if (in_array($cleaned, $aliases, true)) {
                    $colIndexMap[$key] = $idx;
                    $foundKeys[$key] = true;
                    break;
                }
            }
        }

        $missing = [];
        // Customer name, P/N, and Order Date are core requirements
        $essentialKeys = ['customer_name', 'p_n', 'order_date'];
        foreach ($essentialKeys as $reqKey) {
            if (!isset($foundKeys[$reqKey])) {
                $missing[] = array_values(static::$headerAliases[$reqKey])[0] ?? $reqKey;
            }
        }

        return [
            'column_index_map' => $colIndexMap,
            'missing_headers'  => $missing,
        ];
    }

    /**
     * Extract normalized field values from a spreadsheet row
     */
    protected function extractRowData(array $rowData, array $colIndices): array
    {
        $extracted = [];

        foreach (static::$headerAliases as $key => $aliases) {
            $extracted[$key] = null;
            if (isset($colIndices[$key])) {
                $cellIdx = $colIndices[$key];
                $val = $rowData[$cellIdx] ?? null;

                // Handle date values specifically
                if (in_array($key, ['order_date', 'launch_date', 'delivery_date'], true) && $val !== null && $val !== '') {
                    $parsed = $this->parseDate($val);
                    $val = $parsed ?: $val;
                }

                $extracted[$key] = is_string($val) ? trim($val) : $val;
            }
        }

        return $extracted;
    }

    /**
     * Validate data types and constraints for an extracted row
     */
    protected function validateRow(array $data, int $rowIndex): array
    {
        $errors = [];

        // Required fields check
        if (empty($data['customer_name']) || trim((string)$data['customer_name']) === '') {
            $errors['Customer name'] = 'Customer name is required.';
        }

        if (empty($data['p_n'])) {
            $errors['P/N'] = 'P/N (Board/Part name) is required.';
        }

        // Date validation
        foreach (['order_date' => 'Order Date', 'launch_date' => 'Launch Date', 'delivery_date' => 'Delivery date'] as $k => $label) {
            if (!empty($data[$k])) {
                $parsedDate = $this->parseDate($data[$k]);
                if (!$parsedDate) {
                    $errors[$label] = "Invalid date format for '{$data[$k]}'. Expected YYYY-MM-DD or M/D/YYYY.";
                }
            }
        }

        // Numeric fields validation
        foreach (['qty' => 'Qty', 'launch_qty' => 'Launch', 'panel_qty' => 'Panel', 'ups' => 'ups', 'final_qty' => 'Final qty'] as $nk => $nLabel) {
            if ($data[$nk] !== null && $data[$nk] !== '') {
                if (!is_numeric($data[$nk]) || floatval($data[$nk]) < 0) {
                    $errors[$nLabel] = "'{$data[$nk]}' must be a valid numeric value >= 0.";
                }
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Create a new PCB Order record and its associated metadata
     */
    protected function createPcbOrderRecord(string $orderNumber, array $data, ?int $userId = null): PcbOrder
    {
        $orderDate = $this->parseDate($data['order_date']);
        $deliveryDate = $this->parseDate($data['delivery_date']);

        $completedQty = isset($data['final_qty']) && is_numeric($data['final_qty']) ? (int)$data['final_qty'] : 0;
        $status = !empty($data['status']) ? (string)$data['status'] : 'move';
        $customerName = !empty($data['customer_name']) ? (string)$data['customer_name'] : null;

        $order = PcbOrder::create([
            'user_id'       => $userId,
            'order_number'  => $orderNumber,
            'status'        => $status,
            'completed_qty' => $completedQty,
            'delivery_date' => $deliveryDate,
            'created_at'    => $orderDate ? Carbon::parse($orderDate) : now(),
            'updated_at'    => now(),
        ]);

        $this->saveOrderMetas($order->id, $data);

        // Record status history if table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories')) {
            PcbOrderStatusHistory::create([
                'pcb_order_id' => $order->id,
                'status_name'  => $status,
                'remark'       => 'Imported from Manufacturer Excel',
            ]);
        }

        return $order;
    }

    /**
     * Update an existing PCB Order record and its metadata
     */
    protected function updatePcbOrderRecord(PcbOrder $order, array $data, ?int $userId = null): PcbOrder
    {
        $deliveryDate = $this->parseDate($data['delivery_date']);
        if ($deliveryDate) {
            $order->delivery_date = $deliveryDate;
        }

        if (isset($data['final_qty']) && is_numeric($data['final_qty'])) {
            $order->completed_qty = (int)$data['final_qty'];
        }

        if (!empty($data['status'])) {
            $order->status = (string)$data['status'];
        }

        if ($userId) {
            $order->user_id = $userId;
        }

        $order->save();
        $this->saveOrderMetas($order->id, $data);

        return $order;
    }

    /**
     * Save/update key-value specification metas for an order using bulk insert
     */
    protected function saveOrderMetas(int $orderId, array $data): void
    {
        $metaKeyMapping = [
            'order_date'       => 'order_date',
            'launch_date'      => 'launch_date',
            'delivery_date'    => 'delivery_date',
            'quote_number'     => 'quote_number',
            'c_g'              => 'c_g',
            'tool'             => 'tool',
            'combo'            => 'combo',
            'customer_name'    => 'customer_name',
            'layer'            => 'layer',
            'mask'             => 'solder_mask',
            'p_n'              => 'part_number',
            'production_noted' => 'production_noted',
            'qty'              => 'qty',
            'launch_qty'       => 'launch_qty',
            'panel_qty'        => 'panel_qty',
            'ups'              => 'ups',
            'final_qty'        => 'final_qty',
            'status'           => 'status',
            'bill_number'      => 'bill_number',
        ];

        $now = now();
        $metaRows = [];
        $addedKeys = [];

        foreach ($metaKeyMapping as $dataKey => $metaKey) {
            $val = $data[$dataKey] ?? null;
            if ($val !== null && $val !== '') {
                if (in_array($dataKey, ['order_date', 'launch_date', 'delivery_date'], true)) {
                    $parsed = $this->parseDate($val);
                    $val = $parsed ?: $val;
                }

                if (!isset($addedKeys[$metaKey])) {
                    $addedKeys[$metaKey] = true;
                    $metaRows[] = [
                        'pcb_order_id' => $orderId,
                        'meta_key'     => $metaKey,
                        'meta_value'   => (string)$val,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
            }
        }

        // Additional aliases for seamless frontend compatibility
        if (!empty($data['p_n'])) {
            foreach (['board_name', 'p_n'] as $aliasKey) {
                if (!isset($addedKeys[$aliasKey])) {
                    $addedKeys[$aliasKey] = true;
                    $metaRows[] = [
                        'pcb_order_id' => $orderId,
                        'meta_key'     => $aliasKey,
                        'meta_value'   => (string)$data['p_n'],
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
            }
        }
        if (!empty($data['mask'])) {
            foreach (['mask', 'pcb_color'] as $aliasKey) {
                if (!isset($addedKeys[$aliasKey])) {
                    $addedKeys[$aliasKey] = true;
                    $metaRows[] = [
                        'pcb_order_id' => $orderId,
                        'meta_key'     => $aliasKey,
                        'meta_value'   => (string)$data['mask'],
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
            }
        }

        if (!empty($metaRows)) {
            PcbOrderMeta::where('pcb_order_id', $orderId)->delete();
            PcbOrderMeta::insert($metaRows);
        }
    }

    /**
     * Parse date input into standard YYYY-MM-DD
     */
    protected function parseDate($val): ?string
    {
        if (empty($val)) return null;

        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d');
        }

        try {
            $c = Carbon::parse((string)$val);
            return $c->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if all cells in a row are empty
     */
    protected function isRowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}

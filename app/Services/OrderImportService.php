<?php

namespace App\Services;

use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\PcbOrderStatusHistory;
use App\Models\PcbUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * Perform lightweight header validation during upload request
     */
    public function validateHeaderOnly(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [
                'success' => false,
                'message' => 'Uploaded file is unreadable or missing.',
            ];
        }

        try {
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
                    'message' => 'Required manufacturer columns are missing: ' . implode(', ', $headerMap['missing_headers']),
                    'missing_headers' => $headerMap['missing_headers'],
                ];
            }

            $totalRows = 0;
            $countRows = count($allRows);
            for ($i = 1; $i < $countRows; $i++) {
                if (!$this->isRowEmpty($allRows[$i])) {
                    $totalRows++;
                }
            }

            return [
                'success'    => true,
                'total_rows' => $totalRows,
                'header_map' => $headerMap['column_index_map'],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to open spreadsheet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Queue an uploaded file for background import processing
     */
    public function queueImportFile($file, string $duplicateAction = 'skip', ?int $adminId = null): array
    {
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return [
                'success' => false,
                'message' => 'Invalid file format. Please upload a .xlsx or .xls file.',
            ];
        }

        $tempPath = $file->getRealPath();
        $headerCheck = $this->validateHeaderOnly($tempPath);

        if (!$headerCheck['success']) {
            return $headerCheck;
        }

        // Store file temporarily in storage/app/imports/pcb/
        $generatedName = date('Ymd_His') . '_' . \Illuminate\Support\Str::random(10) . '.' . $ext;
        $storedRelPath = $file->storeAs('imports/pcb', $generatedName, 'local');

        $import = \App\Models\PcbImport::create([
            'original_file_name' => $originalName,
            'file_name'          => $generatedName,
            'file_path'          => $storedRelPath,
            'file_type'          => $ext,
            'file_size'          => $file->getSize(),
            'status'             => 'queued',
            'total_rows'         => $headerCheck['total_rows'],
            'duplicate_action'   => $duplicateAction,
            'created_by'         => $adminId ?: 1,
        ]);

        // Dispatch background job
        \App\Jobs\ProcessPcbImportJob::dispatch($import->id);

        return [
            'success' => true,
            'message' => 'Import file uploaded and queued for background processing.',
            'import'  => $import,
        ];
    }

    /**
     * Process background import job execution
     */
    public function processBackgroundImport(\App\Models\PcbImport $import): void
    {
        $import->update([
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        $fullPath = Storage::disk('local')->path($import->file_path);
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/' . $import->file_path);
        }

        if (!file_exists($fullPath)) {
            $import->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => "Import file not found at {$fullPath}",
            ]);
            return;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if (empty($allRows) || count($allRows) < 1) {
            $import->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => 'Spreadsheet is empty.',
            ]);
            return;
        }

        $rawHeaders = $allRows[0];
        $headerMap = $this->mapHeaders($rawHeaders);
        if (!empty($headerMap['missing_headers'])) {
            $import->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => 'Missing required headers: ' . implode(', ', $headerMap['missing_headers']),
            ]);
            return;
        }

        $colIndices = $headerMap['column_index_map'];
        $duplicateAction = $import->duplicate_action ?: 'skip';

        // Memory caches for high performance & deduplication
        $existingOrders = PcbOrder::select('id', 'order_number')->get();
        $existingOrderMap = [];
        foreach ($existingOrders as $eo) {
            if (!empty($eo->order_number)) {
                $existingOrderMap[strtolower($eo->order_number)] = $eo->id;
            }
        }

        $customerCache = $this->buildCustomerCache();
        $existingCustomersUsedCount = 0;
        $newCustomersCreatedCount = 0;
        $usedExistingCustomerIds = [];

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

        $totalRowsInSheet = count($allRows);
        $processedCount = 0;
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $duplicateCount = 0;

        DB::beginTransaction();

        try {
            for ($i = 1; $i < $totalRowsInSheet; $i++) {
                $rowData = $allRows[$i];
                $excelRowNumber = $i + 1;

                if ($this->isRowEmpty($rowData)) {
                    continue;
                }

                $processedCount++;

                // Idempotency check: if row already completed, skip database work
                $existingImportRow = \App\Models\PcbImportRow::where('import_id', $import->id)
                    ->where('row_number', $excelRowNumber)
                    ->first();

                if ($existingImportRow && in_array($existingImportRow->status, ['completed', 'skipped'], true)) {
                    if ($existingImportRow->status === 'completed') {
                        $importedCount++;
                    } else {
                        $skippedCount++;
                    }
                    continue;
                }

                $extracted = $this->extractRowData($rowData, $colIndices);
                $validation = $this->validateRow($extracted, $excelRowNumber);

                if (!$validation['valid']) {
                    $failedCount++;
                    
                    foreach ($validation['errors'] as $colName => $errMsg) {
                        \App\Models\PcbImportError::create([
                            'import_id'     => $import->id,
                            'row_number'    => $excelRowNumber,
                            'column_name'   => $colName,
                            'value'         => is_scalar($extracted[strtolower($colName)] ?? null) ? (string)($extracted[strtolower($colName)] ?? '') : null,
                            'error_message' => $errMsg,
                        ]);
                    }

                    \App\Models\PcbImportRow::updateOrCreate([
                        'import_id'  => $import->id,
                        'row_number' => $excelRowNumber,
                    ], [
                        'status'        => 'failed',
                        'error_message' => implode(' | ', array_values($validation['errors'])),
                        'processed_at'  => now(),
                    ]);

                    continue;
                }

                // Customer Resolution
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

                // Duplicate Order Resolution
                $toolVal = trim($extracted['tool'] ?? '');
                $toolValLower = strtolower($toolVal);
                $existingOrderId = ($toolValLower !== '' && isset($existingOrderMap[$toolValLower])) ? $existingOrderMap[$toolValLower] : null;

                $finalOrderId = null;
                $rowStatus = 'completed';

                if ($existingOrderId && $duplicateAction !== 'create_new') {
                    $duplicateCount++;
                    if ($duplicateAction === 'skip') {
                        $skippedCount++;
                        $rowStatus = 'skipped';
                        $finalOrderId = $existingOrderId;
                    } elseif ($duplicateAction === 'update') {
                        $existingOrder = PcbOrder::find($existingOrderId);
                        if ($existingOrder) {
                            $this->updatePcbOrderRecord($existingOrder, $extracted, $resolvedUserId);
                            $updatedCount++;
                            $finalOrderId = $existingOrder->id;
                        }
                    }
                } else {
                    $orderNumber = $toolVal;
                    if (empty($orderNumber) || ($existingOrderId && $duplicateAction === 'create_new')) {
                        $nextNumericId++;
                        $orderNumber = 'M' . str_pad($nextNumericId, 4, '0', STR_PAD_LEFT);
                    }

                    $newOrder = $this->createPcbOrderRecord($orderNumber, $extracted, $resolvedUserId);
                    $existingOrderMap[strtolower($orderNumber)] = $newOrder->id;
                    $importedCount++;
                    $finalOrderId = $newOrder->id;
                }

                \App\Models\PcbImportRow::updateOrCreate([
                    'import_id'  => $import->id,
                    'row_number' => $excelRowNumber,
                ], [
                    'status'       => $rowStatus,
                    'customer_id'  => $resolvedUserId,
                    'order_id'     => $finalOrderId,
                    'processed_at' => now(),
                ]);

                // Update progress every 25 rows
                if ($processedCount % 25 === 0) {
                    $import->update([
                        'processed_rows'     => $processedCount,
                        'successful_rows'    => ($importedCount + $updatedCount),
                        'failed_rows'        => $failedCount,
                        'skipped_rows'       => $skippedCount,
                        'duplicate_rows'     => $duplicateCount,
                        'existing_customers' => $existingCustomersUsedCount,
                        'new_customers'      => $newCustomersCreatedCount,
                    ]);
                    DB::commit();
                    DB::beginTransaction();
                }
            }

            DB::commit();

            $import->update([
                'status'             => 'completed',
                'completed_at'       => now(),
                'processed_rows'     => $processedCount,
                'successful_rows'    => ($importedCount + $updatedCount),
                'failed_rows'        => $failedCount,
                'skipped_rows'       => $skippedCount,
                'duplicate_rows'     => $duplicateCount,
                'existing_customers' => $existingCustomersUsedCount,
                'new_customers'      => $newCustomersCreatedCount,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $import->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => 'Import processing failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
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

            // Include all preview items (up to 1000 rows)
            if (count($previewItems) < 1000) {
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

        if (empty($data['p_n']) || trim((string)$data['p_n']) === '') {
            $errors['P/N'] = 'P/N (Board/Part name) is required.';
        }

        if (empty($data['order_date']) || trim((string)$data['order_date']) === '') {
            $errors['Order Date'] = 'Order date is required.';
        } else {
            $parsedDate = $this->parseDate($data['order_date']);
            if (!$parsedDate) {
                $errors['Order Date'] = "Invalid date format for '{$data['order_date']}'. Expected YYYY-MM-DD or M/D/YYYY.";
            }
        }

        if ($data['qty'] === null || $data['qty'] === '' || trim((string)$data['qty']) === '') {
            $errors['Qty'] = 'Order Qty is required.';
        } elseif (!is_numeric($data['qty']) || floatval($data['qty']) < 0) {
            $errors['Qty'] = "'{$data['qty']}' must be a valid numeric value >= 0.";
        }

        // Other Date fields validation
        foreach (['launch_date' => 'Launch Date', 'delivery_date' => 'Delivery date'] as $k => $label) {
            if (!empty($data[$k])) {
                $parsedDate = $this->parseDate($data[$k]);
                if (!$parsedDate) {
                    $errors[$label] = "Invalid date format for '{$data[$k]}'. Expected YYYY-MM-DD or M/D/YYYY.";
                }
            }
        }

        // Other Numeric fields validation
        foreach (['launch_qty' => 'Launch', 'panel_qty' => 'Panel', 'ups' => 'ups', 'final_qty' => 'Final qty'] as $nk => $nLabel) {
            if (isset($data[$nk]) && $data[$nk] !== null && $data[$nk] !== '' && trim((string)$data[$nk]) !== '') {
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
        $billNumber = !empty($data['bill_number']) ? (string)$data['bill_number'] : null;

        $order = PcbOrder::create([
            'user_id'       => $userId,
            'order_number'  => $orderNumber,
            'status'        => $status,
            'completed_qty' => $completedQty,
            'delivery_date' => $deliveryDate,
            'bill_number'   => $billNumber,
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

        if (!empty($data['bill_number'])) {
            $order->bill_number = (string)$data['bill_number'];
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

        $str = trim((string)$val);
        if ($str === '' || $str === '-' || strtolower($str) === 'n/a') return null;

        // 1. Excel numeric timestamp
        if (is_numeric($str) && (float)$str > 10000 && (float)$str < 100000) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float)$str);
                return $dt ? $dt->format('Y-m-d') : null;
            } catch (\Throwable $e) {}
        }

        // 2. Custom regex parsing for M-D-Y / D-M-Y / Y-M-D formats with - or /
        $normalized = str_replace('/', '-', $str);
        $parts = explode('-', $normalized);
        if (count($parts) === 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
            $p1 = (int)$parts[0];
            $p2 = (int)$parts[1];
            $p3 = (int)$parts[2];

            if ($p3 > 1000) {
                // Year at end (e.g. 6-30-2026 or 30-06-2026)
                if ($p1 > 12 && $p2 <= 12) {
                    return sprintf('%04d-%02d-%02d', $p3, $p2, $p1);
                } elseif ($p2 > 12 && $p1 <= 12) {
                    return sprintf('%04d-%02d-%02d', $p3, $p1, $p2);
                } elseif ($p1 <= 12 && $p2 <= 12) {
                    return sprintf('%04d-%02d-%02d', $p3, $p1, $p2);
                }
            } elseif ($p1 > 1000) {
                // Year at start (e.g. 2026-06-30)
                if ($p2 <= 12 && $p3 <= 31) {
                    return sprintf('%04d-%02d-%02d', $p1, $p2, $p3);
                }
            }
        }

        // 3. Fallback Carbon parse
        try {
            $c = Carbon::parse($str);
            return $c->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stage an Excel file into pcb_imports and pcb_import_rows without modifying production tables
     */
    public function stageImportFile(string $filePath, string $originalFileName, ?int $userId = null): \App\Models\PcbImport
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if (empty($allRows) || count($allRows) < 1) {
            throw new \Exception('Spreadsheet is empty.');
        }

        $rawHeaders = $allRows[0];
        $headerMap = $this->mapHeaders($rawHeaders);

        if (!empty($headerMap['missing_headers'])) {
            throw new \Exception('Missing required headers: ' . implode(', ', $headerMap['missing_headers']));
        }

        $colIndices = $headerMap['column_index_map'];

        $existingOrderNumbers = PcbOrder::pluck('order_number')->filter()->toArray();
        $existingOrderMap = array_fill_keys(array_map('strtolower', $existingOrderNumbers), true);
        $customerCache = $this->buildCustomerCache();

        $import = \App\Models\PcbImport::create([
            'original_file_name' => $originalFileName,
            'file_name'          => basename($filePath),
            'file_path'          => $filePath,
            'file_type'          => pathinfo($originalFileName, PATHINFO_EXTENSION) ?: 'xlsx',
            'file_size'          => file_exists($filePath) ? filesize($filePath) : 0,
            'status'             => 'reviewing',
            'created_by'         => $userId,
        ]);

        $validCount = 0;
        $invalidCount = 0;
        $duplicateCount = 0;
        $existingCustomersUsed = 0;
        $virtualCustomerMap = [];
        $totalRows = 0;

        $totalRowsInSheet = count($allRows);
        for ($i = 1; $i < $totalRowsInSheet; $i++) {
            $rowData = $allRows[$i];
            $excelRowNumber = $i + 1;

            if ($this->isRowEmpty($rowData)) {
                continue;
            }

            $totalRows++;
            $extracted = $this->extractRowData($rowData, $colIndices);
            $validation = $this->validateRow($extracted, $excelRowNumber);

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
                $existingCustomersUsed++;
            } elseif (isset($virtualCustomerMap[$normCustomer])) {
                $customerAction = "Use new customer ({$virtualCustomerMap[$normCustomer]['name']})";
            } else {
                $cleanCustName = preg_replace('/\s+/', ' ', $rawCustomerName);
                $virtualCustomerMap[$normCustomer] = ['name' => $cleanCustName];
                $customerAction = 'Create new customer';
                $isNewCustomer = true;
            }

            $toolVal = trim((string)($extracted['tool'] ?? ''));
            $isDuplicate = false;
            $matchedOrderNumber = null;
            if ($toolVal !== '' && isset($existingOrderMap[strtolower($toolVal)])) {
                $isDuplicate = true;
                $matchedOrderNumber = $toolVal;
                $duplicateCount++;
            }

            $isValid = $validation['valid'];
            if ($isValid) {
                $validCount++;
            } else {
                $invalidCount++;
            }

            \App\Models\PcbImportRow::create([
                'import_id'            => $import->id,
                'row_number'           => $excelRowNumber,
                'row_data'             => $extracted,
                'status'               => 'pending',
                'validation_status'    => $isValid ? 'valid' : 'invalid',
                'validation_errors'    => $validation['errors'],
                'customer_action'      => $customerAction,
                'resolved_customer_id' => $resolvedCustomerId,
                'is_new_customer'      => $isNewCustomer,
                'is_duplicate'         => $isDuplicate,
                'matched_order_number' => $matchedOrderNumber,
            ]);
        }

        $import->update([
            'total_rows'         => $totalRows,
            'valid_rows'         => $validCount,
            'invalid_rows'       => $invalidCount,
            'duplicate_rows'     => $duplicateCount,
            'existing_customers' => $existingCustomersUsed,
            'new_customers'      => count($virtualCustomerMap),
        ]);

        return $import;
    }

    /**
     * Get paginated staged rows for an import review session
     */
    public function getStagedRows(int $importId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $import = \App\Models\PcbImport::findOrFail($importId);
        $query = \App\Models\PcbImportRow::where('import_id', $importId);

        if (!empty($filters['validation_status']) && $filters['validation_status'] !== 'all') {
            $query->where('validation_status', $filters['validation_status']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('row_data->customer_name', 'LIKE', "%{$search}%")
                  ->orWhere('row_data->p_n', 'LIKE', "%{$search}%")
                  ->orWhere('row_data->quote_number', 'LIKE', "%{$search}%")
                  ->orWhere('row_data->bill_number', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = (clone $query)->count();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = (clone $query)->orderBy('row_number', 'asc')
                              ->skip($offset)
                              ->take($perPage)
                              ->get();

        return [
            'success'      => true,
            'import'       => $import,
            'data'         => $rows,
            'total'        => $totalRows,
            'page'         => $page,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_pages'  => $totalPages,
            'last_page'    => $totalPages,
        ];
    }

    /**
     * Update a single cell value in a staged row and re-evaluate validation
     */
    public function updateStagedCell(int $importId, int $rowId, string $fieldKey, mixed $newValue): array
    {
        $row = \App\Models\PcbImportRow::where('import_id', $importId)->where('id', $rowId)->firstOrFail();
        $data = $row->row_data ?? [];
        $data[$fieldKey] = is_string($newValue) ? trim($newValue) : $newValue;

        if (in_array($fieldKey, ['order_date', 'launch_date', 'delivery_date'], true) && !empty($data[$fieldKey])) {
            $parsed = $this->parseDate($data[$fieldKey]);
            if ($parsed) {
                $data[$fieldKey] = $parsed;
            }
        }

        $validation = $this->validateRow($data, $row->row_number);
        $isValid = $validation['valid'];

        $customerCache = $this->buildCustomerCache();
        $rawCustomerName = trim((string)($data['customer_name'] ?? ''));
        $normCustomer = $this->normalizeCustomerName($rawCustomerName);
        $customerAction = '';
        $resolvedCustomerId = null;
        $isNewCustomer = false;

        if ($normCustomer === '') {
            $customerAction = 'Customer name is required';
        } elseif (isset($customerCache[$normCustomer])) {
            $resolvedCustomerId = $customerCache[$normCustomer]['id'];
            $customerAction = "Existing customer #{$resolvedCustomerId}";
        } else {
            $cleanCustName = preg_replace('/\s+/', ' ', $rawCustomerName);
            $customerAction = "Create new customer ({$cleanCustName})";
            $isNewCustomer = true;
        }

        $row->update([
            'row_data'             => $data,
            'validation_status'    => $isValid ? 'valid' : 'invalid',
            'validation_errors'    => $validation['errors'],
            'customer_action'      => $customerAction,
            'resolved_customer_id' => $resolvedCustomerId,
            'is_new_customer'      => $isNewCustomer,
        ]);

        $import = \App\Models\PcbImport::findOrFail($importId);
        $validCount = \App\Models\PcbImportRow::where('import_id', $importId)->where('validation_status', 'valid')->count();
        $invalidCount = \App\Models\PcbImportRow::where('import_id', $importId)->where('validation_status', 'invalid')->count();

        $import->update([
            'valid_rows'   => $validCount,
            'invalid_rows' => $invalidCount,
        ]);

        return [
            'success' => true,
            'row'     => $row->fresh(),
            'import'  => $import->fresh(),
        ];
    }

    /**
     * Final validation & queue dispatch for staged import session
     */
    public function startStagedImport(int $importId, string $duplicateAction = 'skip'): array
    {
        $import = \App\Models\PcbImport::findOrFail($importId);

        $invalidCount = \App\Models\PcbImportRow::where('import_id', $importId)->where('validation_status', 'invalid')->count();
        if ($invalidCount > 0) {
            return [
                'success' => false,
                'message' => "Import cannot start: {$invalidCount} invalid rows need attention.",
                'invalid_rows' => $invalidCount,
            ];
        }

        $import->update([
            'status'           => 'queued',
            'duplicate_action' => $duplicateAction,
        ]);

        \App\Jobs\ProcessPcbImportJob::dispatch($importId);

        return [
            'success' => true,
            'message' => 'Import successfully queued for background processing.',
            'import'  => $import->fresh(),
        ];
    }

    /**
     * Process queued background import reading from pcb_import_rows staging table
     */
    public function processBackgroundImportFromStaging(\App\Models\PcbImport $import): void
    {
        $import->update([
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        $customerCache = $this->buildCustomerCache();
        $existingCustomersUsedCount = 0;
        $newCustomersCreatedCount = 0;
        $usedExistingCustomerIds = [];

        $existingOrders = PcbOrder::select('id', 'order_number')->get();
        $existingOrderMap = [];
        foreach ($existingOrders as $eo) {
            if (!empty($eo->order_number)) {
                $existingOrderMap[strtolower($eo->order_number)] = $eo->id;
            }
        }

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

        $stagedRows = \App\Models\PcbImportRow::where('import_id', $import->id)
            ->where('validation_status', 'valid')
            ->orderBy('row_number')
            ->get();

        $totalToProcess = $stagedRows->count();
        $processedCount = 0;
        $successfulCount = 0;
        $failedCount = 0;

        foreach ($stagedRows as $row) {
            $data = $row->row_data ?? [];
            $excelRowNumber = $row->row_number;

            try {
                DB::beginTransaction();

                $rawCustomerName = trim((string)($data['customer_name'] ?? ''));
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
                        $cleanCustName = preg_replace('/\s+/', ' ', $rawCustomerName);
                        $slug = \Illuminate\Support\Str::slug($cleanCustName);
                        $uniqueEmail = 'customer_' . ($slug ?: 'user') . '_' . substr(md5(strtolower($cleanCustName)), 0, 6) . '@import.local';

                        $newCustomer = PcbUser::create([
                            'name'         => $cleanCustName,
                            'company_name' => $cleanCustName,
                            'email'        => $uniqueEmail,
                            'role'         => 'customer',
                            'password'     => bcrypt(\Illuminate\Support\Str::random(16)),
                            'is_active'    => true,
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

                $toolVal = trim((string)($data['tool'] ?? ''));
                $dupAction = $import->duplicate_action ?: 'skip';
                $existingOrderId = null;

                if ($toolVal !== '' && isset($existingOrderMap[strtolower($toolVal)])) {
                    $existingOrderId = $existingOrderMap[strtolower($toolVal)];
                }

                if ($existingOrderId && $dupAction === 'skip') {
                    $row->update([
                        'status'       => 'skipped',
                        'customer_id'  => $resolvedUserId,
                        'order_id'     => $existingOrderId,
                        'processed_at' => now(),
                    ]);
                    DB::commit();
                    $processedCount++;
                    $successfulCount++;
                    continue;
                }

                if ($existingOrderId && $dupAction === 'update') {
                    $order = PcbOrder::find($existingOrderId);
                    if ($order) {
                        $this->updatePcbOrderRecord($order, $data, $resolvedUserId);
                        $row->update([
                            'status'       => 'completed',
                            'customer_id'  => $resolvedUserId,
                            'order_id'     => $order->id,
                            'processed_at' => now(),
                        ]);
                        DB::commit();
                        $processedCount++;
                        $successfulCount++;
                        continue;
                    }
                }

                $nextNumericId++;
                $orderNum = 'M' . $nextNumericId;
                $order = $this->createPcbOrderRecord($orderNum, $data, $resolvedUserId);

                if ($toolVal !== '') {
                    $existingOrderMap[strtolower($toolVal)] = $order->id;
                }

                $row->update([
                    'status'       => 'completed',
                    'customer_id'  => $resolvedUserId,
                    'order_id'     => $order->id,
                    'processed_at' => now(),
                ]);

                DB::commit();
                $processedCount++;
                $successfulCount++;
            } catch (\Throwable $ex) {
                DB::rollBack();
                $failedCount++;
                $row->update([
                    'status'        => 'failed',
                    'error_message' => $ex->getMessage(),
                    'processed_at'  => now(),
                ]);

                \App\Models\PcbImportError::create([
                    'import_id'     => $import->id,
                    'row_number'    => $excelRowNumber,
                    'column_name'   => 'General',
                    'value'         => null,
                    'error_message' => $ex->getMessage(),
                ]);
            }

            if ($processedCount % 10 === 0 || $processedCount === $totalToProcess) {
                $import->update([
                    'processed_rows'     => $processedCount,
                    'successful_rows'    => $successfulCount,
                    'failed_rows'        => $failedCount,
                    'existing_customers' => $existingCustomersUsedCount,
                    'new_customers'      => $newCustomersCreatedCount,
                ]);
            }
        }

        $import->update([
            'status'             => $failedCount > 0 && $successfulCount === 0 ? 'failed' : 'completed',
            'processed_rows'     => $processedCount,
            'successful_rows'    => $successfulCount,
            'failed_rows'        => $failedCount,
            'existing_customers' => $existingCustomersUsedCount,
            'new_customers'      => $newCustomersCreatedCount,
            'completed_at'       => now(),
        ]);
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

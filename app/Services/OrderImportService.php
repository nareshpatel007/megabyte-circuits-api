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
            $spreadsheet = $this->loadSpreadsheetFast($filePath);
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

        $fullPath = storage_path('app/' . ltrim($import->file_path, '/\\'));
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/public/' . ltrim($import->file_path, '/\\'));
        }

        if (!file_exists($fullPath)) {
            $import->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'error_message' => "Import file not found at {$fullPath}",
            ]);
            return;
        }

        $spreadsheet = $this->loadSpreadsheetFast($fullPath);
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
                    if (empty($orderNumber)) {
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

            $this->cleanupCompletedImport($import);
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
        $spreadsheet = $this->loadSpreadsheetFast($filePath);
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
        $spreadsheet = $this->loadSpreadsheetFast($filePath);
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
                    if (empty($orderNumber)) {
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
        // Customer name and Order Date are core requirements
        $essentialKeys = ['customer_name', 'order_date'];
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

                // Default numeric quantity fields to 0 if invalid or missing
                if (in_array($key, ['qty', 'launch_qty', 'panel_qty', 'ups', 'final_qty'], true)) {
                    if ($val === null || $val === '' || !is_numeric(trim((string)$val)) || floatval(trim((string)$val)) < 0) {
                        $val = 0;
                    } else {
                        $val = (int)trim((string)$val);
                    }
                }

                $extracted[$key] = is_string($val) ? trim($val) : $val;
            } else {
                // If column not found in spreadsheet header, default quantity fields to 0
                if (in_array($key, ['qty', 'launch_qty', 'panel_qty', 'ups', 'final_qty'], true)) {
                    $extracted[$key] = 0;
                }
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

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Create a new PCB Order record and its associated metadata
     */
    /**
     * Create a new PCB Order record and its associated metadata
     */
    protected function createPcbOrderRecord(string $orderNumber, array $data, ?int $userId = null): PcbOrder
    {
        $orderDate = $this->parseDate($data['order_date'] ?? null);
        $launchDate = $this->parseDate($data['launch_date'] ?? null);
        $deliveryDate = $this->parseDate($data['delivery_date'] ?? null);

        $orderQty = isset($data['qty']) && is_numeric($data['qty']) ? (int)$data['qty'] : (isset($data['order_qty']) && is_numeric($data['order_qty']) ? (int)$data['order_qty'] : 0);
        $launchQty = isset($data['launch_qty']) && is_numeric($data['launch_qty']) ? (int)$data['launch_qty'] : 0;
        $panelQty = isset($data['panel_qty']) && is_numeric($data['panel_qty']) ? (int)$data['panel_qty'] : 0;
        $upsQty = isset($data['ups']) && is_numeric($data['ups']) ? (int)$data['ups'] : (isset($data['ups_qty']) && is_numeric($data['ups_qty']) ? (int)$data['ups_qty'] : 0);
        $finalQty = isset($data['final_qty']) && is_numeric($data['final_qty']) ? (int)$data['final_qty'] : 0;

        // failed_qty logic: launch_qty - final_qty
        $failedQty = max(0, $launchQty - $finalQty);

        $completedQty = $finalQty > 0 ? $finalQty : $orderQty;
        $rawStatus = !empty($data['status']) ? trim((string)$data['status']) : '';
        $status = (empty($rawStatus) || strtolower($rawStatus) === 'move') ? 'Completed' : $rawStatus;
        $billNumber = !empty($data['bill_number']) ? (string)$data['bill_number'] : null;

        // P/N Resolution -> gerber_files table
        $gerberFileId = null;
        $pnVal = trim((string)($data['p_n'] ?? ''));
        if ($pnVal !== '') {
            if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                $gf = \Illuminate\Support\Facades\DB::table('gerber_files')
                    ->where('original_name', $pnVal)
                    ->when($userId, fn($q) => $q->where('user_id', $userId))
                    ->whereNull('deleted_at')
                    ->first();

                if ($gf) {
                    $gerberFileId = $gf->id;
                } else {
                    $gerberFileId = \Illuminate\Support\Facades\DB::table('gerber_files')->insertGetId([
                        'user_id'       => $userId,
                        'original_name' => $pnVal,
                        'file_name'      => $pnVal,
                        'board_name'     => $pnVal,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }

        $layers = !empty($data['layer']) ? (string)$data['layer'] : null;
        $mask = !empty($data['mask']) ? (string)$data['mask'] : null;

        $orderPayload = [
            'user_id'        => $userId,
            'order_number'   => $orderNumber,
            'q_no'           => !empty($data['quote_number']) ? (string)$data['quote_number'] : (!empty($data['q_no']) ? (string)$data['q_no'] : null),
            'c_g'            => !empty($data['c_g']) ? (string)$data['c_g'] : null,
            'combo'          => !empty($data['combo']) ? (string)$data['combo'] : null,
            'layers'         => $layers,
            'mask'           => $mask,
            'gerber_file_id' => $gerberFileId,
            'status'         => $status,
            'completed_qty'  => $completedQty,
            'order_qty'      => $orderQty,
            'launch_qty'     => $launchQty,
            'panel_qty'      => $panelQty,
            'ups_qty'        => $upsQty,
            'final_qty'      => $finalQty,
            'failed_qty'     => $failedQty,
            'delivery_date'  => $deliveryDate,
            'launch_date'    => $launchDate,
            'bill_number'    => $billNumber,
            'created_at'     => $orderDate ? Carbon::parse($orderDate) : null,
            'updated_at'     => now(),
        ];

        $order = PcbOrder::create($orderPayload);

        // Production noted logic -> pcb_order_notes table
        $prodNote = trim((string)($data['production_noted'] ?? ''));
        if ($prodNote !== '') {
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_notes')->insert([
                    'pcb_order_id' => $order->id,
                    'admin_id'     => null,
                    'note'         => $prodNote,
                    'is_internal'  => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

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
        $orderDate = $this->parseDate($data['order_date'] ?? null);
        $launchDate = $this->parseDate($data['launch_date'] ?? null);
        $deliveryDate = $this->parseDate($data['delivery_date'] ?? null);

        $order->created_at = $orderDate ? Carbon::parse($orderDate) : null;
        $order->launch_date = $launchDate;
        $order->delivery_date = $deliveryDate;

        if (isset($data['quote_number'])) {
            $order->q_no = (string)$data['quote_number'];
        } elseif (isset($data['q_no'])) {
            $order->q_no = (string)$data['q_no'];
        }

        if (isset($data['c_g'])) {
            $order->c_g = (string)$data['c_g'];
        }

        if (isset($data['combo'])) {
            $order->combo = (string)$data['combo'];
        }

        if (isset($data['layer'])) {
            $order->layers = (string)$data['layer'];
        }

        if (isset($data['mask'])) {
            $order->mask = (string)$data['mask'];
        }

        if (isset($data['qty']) && is_numeric($data['qty'])) {
            $order->order_qty = (int)$data['qty'];
        } elseif (isset($data['order_qty']) && is_numeric($data['order_qty'])) {
            $order->order_qty = (int)$data['order_qty'];
        }

        if (isset($data['launch_qty']) && is_numeric($data['launch_qty'])) {
            $order->launch_qty = (int)$data['launch_qty'];
        }

        if (isset($data['panel_qty']) && is_numeric($data['panel_qty'])) {
            $order->panel_qty = (int)$data['panel_qty'];
        }

        if (isset($data['ups']) && is_numeric($data['ups'])) {
            $order->ups_qty = (int)$data['ups'];
        } elseif (isset($data['ups_qty']) && is_numeric($data['ups_qty'])) {
            $order->ups_qty = (int)$data['ups_qty'];
        }

        if (isset($data['final_qty']) && is_numeric($data['final_qty'])) {
            $order->final_qty = (int)$data['final_qty'];
            $order->completed_qty = (int)$data['final_qty'];
        }

        // failed_qty logic: launch_qty - final_qty
        $launchQty = (int)($order->launch_qty ?? 0);
        $finalQty = (int)($order->final_qty ?? 0);
        $order->failed_qty = max(0, $launchQty - $finalQty);

        if (!empty($data['status'])) {
            $rawStatus = trim((string)$data['status']);
            $order->status = strtolower($rawStatus) === 'move' ? 'Completed' : $rawStatus;
        }

        if (!empty($data['bill_number'])) {
            $order->bill_number = (string)$data['bill_number'];
        }

        if ($userId) {
            $order->user_id = $userId;
        }

        // P/N Resolution -> gerber_files table
        $pnVal = trim((string)($data['p_n'] ?? ''));
        if ($pnVal !== '') {
            if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                $gf = \Illuminate\Support\Facades\DB::table('gerber_files')
                    ->where('original_name', $pnVal)
                    ->when($order->user_id, fn($q) => $q->where('user_id', $order->user_id))
                    ->whereNull('deleted_at')
                    ->first();

                if ($gf) {
                    $order->gerber_file_id = $gf->id;
                } else {
                    $order->gerber_file_id = \Illuminate\Support\Facades\DB::table('gerber_files')->insertGetId([
                        'user_id'       => $order->user_id,
                        'original_name' => $pnVal,
                        'file_name'      => $pnVal,
                        'board_name'     => $pnVal,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }

        $order->save();

        // Production noted logic -> pcb_order_notes table
        $prodNote = trim((string)($data['production_noted'] ?? ''));
        if ($prodNote !== '') {
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_notes')->insert([
                    'pcb_order_id' => $order->id,
                    'admin_id'     => null,
                    'note'         => $prodNote,
                    'is_internal'  => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

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
     * Parse and auto-correct date input into standard YYYY-MM-DD
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

        // Clean up common separators: replace slashes, dots, underscores, spaces with dash
        $normalized = preg_replace('/[\/\._\s]+/', '-', $str);
        $normalized = preg_replace('/[^\d\-]/', '', $normalized);
        $parts = array_values(array_filter(explode('-', $normalized), fn($p) => $p !== ''));

        if (count($parts) === 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
            $p1 = (int)$parts[0];
            $p2 = (int)$parts[1];
            $p3 = (int)$parts[2];

            $year = null;
            $month = null;
            $day = null;

            if ($p1 > 1000) {
                // Year at start (e.g. 2026-04-24)
                $year = $p1;
                $month = min(12, max(1, $p2));
                $day = min(31, max(1, $p3));
            } else {
                // Year at end (e.g. 24-04-223 or 24-04-23 or 24-04-2023)
                if ($p3 >= 1000 && $p3 <= 2100) {
                    $year = $p3;
                } elseif ($p3 >= 100 && $p3 < 1000) {
                    // Typo year like 223 or 023 -> 2023
                    $year = 2000 + ($p3 % 100);
                } elseif ($p3 >= 0 && $p3 < 100) {
                    // 2-digit year like 23 or 26 -> 2023 or 2026
                    $year = 2000 + $p3;
                } else {
                    $year = 2000 + (int)substr((string)$p3, -2);
                }

                if ($p1 > 12 && $p2 <= 12) {
                    // DD-MM-YYYY (e.g. 24-04-2023)
                    $day = min(31, max(1, $p1));
                    $month = min(12, max(1, $p2));
                } elseif ($p2 > 12 && $p1 <= 12) {
                    // MM-DD-YYYY (e.g. 04-24-2023)
                    $month = min(12, max(1, $p1));
                    $day = min(31, max(1, $p2));
                } else {
                    // Both <= 12 (e.g. 24-04-2023 -> 24 is day), default DD-MM-YYYY
                    $day = min(31, max(1, $p1));
                    $month = min(12, max(1, $p2));
                }
            }

            if ($year && $month && $day) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
     * Helper to load Excel spreadsheet in high-performance mode:
     * - Disables PHP time execution limits
     * - Increases memory limit to 1024M
     * - Uses setReadDataOnly(true) to avoid loading cell formatting overhead
     */
    public function loadSpreadsheetFast(string $filePath): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            if (method_exists($reader, 'setReadEmptyCells')) {
                $reader->setReadEmptyCells(false);
            }
            return $reader->load($filePath);
        } catch (\Throwable $e) {
            return IOFactory::load($filePath);
        }
    }

    /**
     * Stage an Excel file into pcb_imports and pcb_import_rows without modifying production tables
     */
    public function stageImportFile(string $filePath, string $originalFileName, ?int $userId = null): \App\Models\PcbImport
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $spreadsheet = $this->loadSpreadsheetFast($filePath);
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
        $now = now()->toDateTimeString();
        $insertBatch = [];

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

            $insertBatch[] = [
                'import_id'            => $import->id,
                'row_number'           => $excelRowNumber,
                'row_data'             => json_encode($extracted),
                'status'               => 'pending',
                'validation_status'    => $isValid ? 'valid' : 'invalid',
                'validation_errors'    => json_encode($validation['errors']),
                'customer_action'      => $customerAction,
                'resolved_customer_id' => $resolvedCustomerId,
                'is_new_customer'      => $isNewCustomer ? 1 : 0,
                'is_duplicate'         => $isDuplicate ? 1 : 0,
                'matched_order_number' => $matchedOrderNumber,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];

            if (count($insertBatch) >= 250) {
                \App\Models\PcbImportRow::insert($insertBatch);
                $insertBatch = [];
            }
        }

        if (!empty($insertBatch)) {
            \App\Models\PcbImportRow::insert($insertBatch);
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

        if (in_array($fieldKey, ['qty', 'launch_qty', 'panel_qty', 'ups', 'final_qty'], true)) {
            $valStr = is_scalar($data[$fieldKey]) ? trim((string)$data[$fieldKey]) : '';
            if ($valStr === '' || !is_numeric($valStr) || floatval($valStr) < 0) {
                $data[$fieldKey] = 0;
            } else {
                $data[$fieldKey] = (int)$valStr;
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
    public function startStagedImport(int $importId, string $duplicateAction = 'update', bool $importValidOnly = false): array
    {
        $import = \App\Models\PcbImport::findOrFail($importId);

        $invalidCount = \App\Models\PcbImportRow::where('import_id', $importId)->where('validation_status', 'invalid')->count();
        $validCount = \App\Models\PcbImportRow::where('import_id', $importId)->where('validation_status', 'valid')->count();

        if ($validCount === 0) {
            return [
                'success' => false,
                'message' => "Import cannot start: No valid rows available for import.",
                'valid_rows' => 0,
            ];
        }

        if ($invalidCount > 0 && !$importValidOnly) {
            return [
                'success' => false,
                'message' => "Import cannot start: {$invalidCount} invalid rows need attention.",
                'invalid_rows' => $invalidCount,
            ];
        }

        if ($invalidCount > 0 && $importValidOnly) {
            \App\Models\PcbImportRow::where('import_id', $importId)
                ->where('validation_status', 'invalid')
                ->update([
                    'status'        => 'skipped',
                    'error_message' => 'Skipped during valid-only import',
                    'processed_at'  => now(),
                ]);
        }

        $import->update([
            'status'           => 'queued',
            'duplicate_action' => $duplicateAction,
        ]);

        return [
            'success' => true,
            'message' => 'Import successfully prepared for processing.',
            'import'  => $import->fresh(),
        ];
    }

    /**
     * Process a single batch chunk (default 100 rows) of staged import data
     */
    public function processImportBatch(\App\Models\PcbImport $import, int $batchSize = 100): array
    {
        if ($import->status === 'cancelled') {
            return [
                'status'  => 'cancelled',
                'message' => 'Import has been cancelled.',
                'import'  => $import->fresh(),
            ];
        }

        if ($import->status !== 'processing') {
            $import->update([
                'status'     => 'processing',
                'started_at' => $import->started_at ?: now(),
            ]);
        }

        $customerCache = $this->buildCustomerCache();
        $existingCustomersUsedCount = $import->existing_customers ?? 0;
        $newCustomersCreatedCount = $import->new_customers ?? 0;
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

        // Fetch un-processed valid rows in chunk
        $chunkRows = \App\Models\PcbImportRow::where('import_id', $import->id)
            ->where('validation_status', 'valid')
            ->where('status', 'pending')
            ->orderBy('row_number', 'asc')
            ->limit($batchSize)
            ->get();

        if ($chunkRows->isNotEmpty()) {
            foreach ($chunkRows as $row) {
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
                            continue;
                        }
                    }

                    $orderNum = $toolVal;
                    if (empty($orderNum)) {
                        $nextNumericId++;
                        $orderNum = 'M' . $nextNumericId;
                    }
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
                } catch (\Throwable $ex) {
                    DB::rollBack();
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
            }
        }

        // Recalculate metrics
        $totalValidRows = \App\Models\PcbImportRow::where('import_id', $import->id)->where('validation_status', 'valid')->count();
        $finalProcessed = \App\Models\PcbImportRow::where('import_id', $import->id)->where('status', '!=', 'pending')->count();
        $finalSuccessful = \App\Models\PcbImportRow::where('import_id', $import->id)->whereIn('status', ['completed', 'skipped'])->count();
        $finalFailed = \App\Models\PcbImportRow::where('import_id', $import->id)->where('status', 'failed')->count();

        $isFullyDone = ($finalProcessed >= $totalValidRows) && ($totalValidRows > 0);
        $finalStatus = $isFullyDone ? ($finalFailed > 0 && $finalSuccessful === 0 ? 'failed' : 'completed') : 'processing';

        $import->update([
            'status'             => $finalStatus,
            'processed_rows'     => $finalProcessed,
            'successful_rows'    => $finalSuccessful,
            'failed_rows'        => $finalFailed,
            'existing_customers' => $existingCustomersUsedCount,
            'new_customers'      => $newCustomersCreatedCount,
            'completed_at'       => $isFullyDone ? now() : null,
        ]);

        if ($finalStatus === 'completed') {
            $this->cleanupCompletedImport($import);
        }

        return [
            'status'          => $finalStatus,
            'is_completed'    => $isFullyDone,
            'processed_rows'  => $finalProcessed,
            'total_rows'      => $totalValidRows,
            'successful_rows' => $finalSuccessful,
            'failed_rows'     => $finalFailed,
            'import'          => $isFullyDone ? null : $import->fresh(),
        ];
    }

    /**
     * Process queued background import reading from pcb_import_rows staging table
     */
    public function processBackgroundImportFromStaging(\App\Models\PcbImport $import): void
    {
        while (true) {
            $res = $this->processImportBatch($import, 100);
            if (!empty($res['is_completed']) || $res['status'] === 'cancelled' || $res['status'] === 'completed' || $res['status'] === 'failed') {
                break;
            }
        }
    }

    /**
     * Purge import history, file from disk, errors, and staged rows upon successful import completion.
     */
    public function cleanupCompletedImport(\App\Models\PcbImport $import): void
    {
        try {
            // Delete file from disk if file_path exists
            if (!empty($import->file_path)) {
                $fullPath = storage_path('app/' . ltrim($import->file_path, '/\\'));
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $publicPath = storage_path('app/public/' . ltrim($import->file_path, '/\\'));
                if (file_exists($publicPath)) {
                    @unlink($publicPath);
                }
            }

            // Purge related staging data
            \App\Models\PcbImportError::where('import_id', $import->id)->delete();
            \App\Models\PcbImportRow::where('import_id', $import->id)->delete();

            // Purge import entry from pcb_imports table
            $import->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to cleanup completed import ID {$import->id}: " . $e->getMessage());
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

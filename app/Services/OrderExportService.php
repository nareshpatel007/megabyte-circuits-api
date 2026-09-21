<?php

namespace App\Services;

use App\Models\PcbOrder;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class OrderExportService
{
    /**
     * Build filtered query based on input search and specification parameters
     */
    public function buildFilteredQuery(array $filters = [])
    {
        $query = PcbOrder::with(['metas', 'statusDetails']);

        $dateField = $filters['date_field'] ?? 'order_date'; // order_date, launch_date, delivery_date, created_at

        // Helper to parse input date safely into YYYY-MM-DD format
        $parseFilterDate = function ($dateStr) {
            if (empty($dateStr)) return null;
            try {
                return Carbon::parse($dateStr)->format('Y-m-d');
            } catch (\Throwable $e) {
                return $dateStr;
            }
        };

        $startDate = $parseFilterDate($filters['start_date'] ?? null);
        $endDate = $parseFilterDate($filters['end_date'] ?? null);

        // Date range filters
        if (!empty($startDate)) {
            if ($dateField === 'created_at') {
                $query->whereDate('created_at', '>=', $startDate);
            } elseif ($dateField === 'delivery_date') {
                $query->where(function ($q) use ($startDate) {
                    $q->whereDate('delivery_date', '>=', $startDate)
                      ->orWhereHas('metas', function ($mq) use ($startDate) {
                          $mq->where('meta_key', 'delivery_date')
                             ->whereDate('meta_value', '>=', $startDate);
                      });
                });
            } else {
                // Filter by order_date, launch_date, or created_at
                $query->where(function ($q) use ($startDate, $dateField) {
                    $q->whereDate('created_at', '>=', $startDate)
                      ->orWhereHas('metas', function ($mq) use ($startDate, $dateField) {
                          $mq->whereIn('meta_key', [$dateField, 'order_date', 'launch_date'])
                             ->whereDate('meta_value', '>=', $startDate);
                      });
                });
            }
        }

        if (!empty($endDate)) {
            if ($dateField === 'created_at') {
                $query->whereDate('created_at', '<=', $endDate);
            } elseif ($dateField === 'delivery_date') {
                $query->where(function ($q) use ($endDate) {
                    $q->whereDate('delivery_date', '<=', $endDate)
                      ->orWhereHas('metas', function ($mq) use ($endDate) {
                          $mq->where('meta_key', 'delivery_date')
                             ->whereDate('meta_value', '<=', $endDate);
                      });
                });
            } else {
                $query->where(function ($q) use ($endDate, $dateField) {
                    $q->whereDate('created_at', '<=', $endDate)
                      ->orWhereHas('metas', function ($mq) use ($endDate, $dateField) {
                          $mq->whereIn('meta_key', [$dateField, 'order_date', 'launch_date'])
                             ->whereDate('meta_value', '<=', $endDate);
                      });
                });
            }
        }

        // Status filter
        if (!empty($filters['status']) && strtolower($filters['status']) !== 'all') {
            $statusVal = $filters['status'];
            if ($statusVal === 'In Production') {
                $query->where(function ($q) {
                    $q->where('status', 'move')
                      ->orWhere('status', 'LIKE', '%production%')
                      ->orWhere('status', 'LIKE', '%pending%');
                });
            } else {
                $query->where('status', $statusVal);
            }
        }

        // Customer Filter
        if (!empty($filters['customer_name'])) {
            $cust = trim($filters['customer_name']);
            $query->where(function ($q) use ($cust) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name')) {
                    $q->where('customer_name', 'LIKE', "%{$cust}%");
                }
                $q->orWhereHas('metas', function ($mq) use ($cust) {
                    $mq->where('meta_key', 'customer_name')
                      ->where('meta_value', 'LIKE', "%{$cust}%");
                });
            });
        }

        // Layer Filter
        if (!empty($filters['layer']) && strtolower($filters['layer']) !== 'all') {
            $layer = trim($filters['layer']);
            $query->whereHas('metas', function ($mq) use ($layer) {
                $mq->whereIn('meta_key', ['layer', 'layers'])
                   ->where('meta_value', $layer);
            });
        }

        // Mask Filter
        if (!empty($filters['mask']) && strtolower($filters['mask']) !== 'all') {
            $mask = trim($filters['mask']);
            $query->whereHas('metas', function ($mq) use ($mask) {
                $mq->whereIn('meta_key', ['mask', 'solder_mask', 'pcb_color'])
                   ->where('meta_value', 'LIKE', "%{$mask}%");
            });
        }

        // C/G Filter
        if (!empty($filters['c_g']) && strtolower($filters['c_g']) !== 'all') {
            $cg = trim($filters['c_g']);
            $query->whereHas('metas', function ($mq) use ($cg) {
                $mq->where('meta_key', 'c_g')
                   ->where('meta_value', 'LIKE', "%{$cg}%");
            });
        }

        // P/N (Part Number) Filter
        if (!empty($filters['p_n'])) {
            $pn = trim($filters['p_n']);
            $query->where(function ($q) use ($pn) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name')) {
                    $q->where('board_name', 'LIKE', "%{$pn}%");
                }
                $q->orWhereHas('metas', function ($mq) use ($pn) {
                    $mq->whereIn('meta_key', ['p_n', 'part_number', 'board_name'])
                      ->where('meta_value', 'LIKE', "%{$pn}%");
                });
            });
        }

        // Q# No. Filter
        if (!empty($filters['quote_number'])) {
            $qNo = trim($filters['quote_number']);
            $query->whereHas('metas', function ($mq) use ($qNo) {
                $mq->whereIn('meta_key', ['quote_number', 'q_no'])
                   ->where('meta_value', 'LIKE', "%{$qNo}%");
            });
        }

        // Bill number Filter
        if (!empty($filters['bill_number'])) {
            $billNo = trim($filters['bill_number']);
            $query->whereHas('metas', function ($mq) use ($billNo) {
                $mq->where('meta_key', 'bill_number')
                   ->where('meta_value', 'LIKE', "%{$billNo}%");
            });
        }

        // General Search Filter
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%");
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name')) {
                    $q->orWhere('customer_name', 'LIKE', "%{$search}%");
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name')) {
                    $q->orWhere('board_name', 'LIKE', "%{$search}%");
                }
                $q->orWhereHas('metas', function ($mq) use ($search) {
                    $mq->where('meta_value', 'LIKE', "%{$search}%");
                });
            });
        }

        // Specific Order IDs filter
        if (!empty($filters['order_ids']) && is_array($filters['order_ids'])) {
            $query->whereIn('id', $filters['order_ids']);
        }

        return $query;
    }

    /**
     * Return paginated filtered output for export preview table in UI
     */
    public function previewFilteredRecords(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $query = $this->buildFilteredQuery($filters);

        $totalCount = (clone $query)->count();
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $offset = ($page - 1) * $perPage;

        $orders = (clone $query)->orderBy('id', 'desc')
                                ->skip($offset)
                                ->take($perPage)
                                ->get();

        $previewRows = $orders->map(function ($order) {
            $getMeta = function ($key, $fallback = '') use ($order) {
                return $order->getMeta($key, $fallback);
            };

            $orderDateVal = $getMeta('order_date', '');
            $orderDateStr = !empty($orderDateVal) ? Carbon::parse($orderDateVal)->format('M d, Y') : ($order->created_at ? $order->created_at->format('M d, Y') : 'N/A');

            $launchDateVal = $getMeta('launch_date', '');
            $launchDateStr = !empty($launchDateVal) ? Carbon::parse($launchDateVal)->format('M d, Y') : 'N/A';

            $deliveryDateVal = $order->delivery_date ? $order->delivery_date->format('M d, Y') : $getMeta('delivery_date', 'N/A');

            return [
                'id'                => $order->id,
                'order_date'        => $orderDateStr,
                'launch_date'       => $launchDateStr,
                'delivery_date'     => $deliveryDateVal,
                'quote_number'      => $getMeta('quote_number', $getMeta('q_no', 'N/A')),
                'c_g'               => $getMeta('c_g', 'GST'),
                'tool'              => $getMeta('tool', $order->order_number),
                'combo'             => $getMeta('combo', '-'),
                'customer_name'     => $order->customer_name ?: $getMeta('customer_name', 'N/A'),
                'layer'             => $getMeta('layer', $getMeta('layers', '1')),
                'mask'              => $getMeta('solder_mask', $getMeta('mask', $getMeta('pcb_color', 'Green'))),
                'board_name'        => $order->board_name ?: $getMeta('part_number', $getMeta('p_n', 'N/A')),
                'production_noted'  => $getMeta('production_noted', '-'),
                'qty'               => is_numeric($getMeta('qty')) ? (int)$getMeta('qty') : (int)$getMeta('quantity', 0),
                'launch_qty'        => is_numeric($getMeta('launch_qty')) ? (int)$getMeta('launch_qty') : 0,
                'panel_qty'         => is_numeric($getMeta('panel_qty')) ? (int)$getMeta('panel_qty') : 0,
                'ups'               => is_numeric($getMeta('ups')) ? (int)$getMeta('ups') : 1,
                'completed_qty'     => (int)($order->completed_qty ?? $getMeta('final_qty', 0)),
                'status'            => $order->status ?: $getMeta('status', 'move'),
                'bill_number'       => $getMeta('bill_number', 'N/A'),
            ];
        });

        return [
            'success'      => true,
            'total'        => $totalCount,
            'total_count'  => $totalCount,
            'page'         => $page,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_pages'  => $totalPages,
            'last_page'    => $totalPages,
            'data'         => $previewRows,
        ];
    }

    /**
     * Generate an Excel spreadsheet matching the exact manufacturer format
     */
    public function generateExport(array $filters = []): Spreadsheet
    {
        $orders = $this->buildFilteredQuery($filters)->orderBy('id', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        // Exact manufacturer headers
        $headers = OrderImportService::$expectedColumns;

        // Write Headers to Row 1
        foreach ($headers as $colIdx => $headerText) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue("{$colLetter}1", $headerText);
        }

        // Apply Header Styling
        $headerRange = 'A1:S1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => '000000'],
                'size'  => 11,
                'name'  => 'Calibri',
            ],
            'alignment' => [
                'vertical'   => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'B0B0B0'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Populate Data Rows starting at Row 2
        $row = 2;
        foreach ($orders as $order) {
            $rowValues = $this->formatOrderRowValues($order);

            foreach ($rowValues as $colIdx => $value) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $cellCoordinate = "{$colLetter}{$row}";

                // Format numeric vs string cells properly
                if (in_array($colIdx, [12, 13, 14, 15, 16], true) && is_numeric($value)) {
                    $sheet->setCellValueExplicit($cellCoordinate, (float)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($cellCoordinate, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }

            $row++;
        }

        // Auto-size columns for clear readability
        foreach (range(1, 19) as $colIdx) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Generate RFC 4180 compliant CSV export
     */
    public function generateCsvExport(array $filters = []): string
    {
        $orders = $this->buildFilteredQuery($filters)->orderBy('id', 'desc')->get();
        $headers = OrderImportService::$expectedColumns;

        $output = fopen('php://temp', 'r+');

        // Add UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Write Header Row
        fputcsv($output, $headers);

        // Write Data Rows
        foreach ($orders as $order) {
            $rowValues = $this->formatOrderRowValues($order);
            fputcsv($output, $rowValues);
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Format a single order into array matching the 19 manufacturer columns
     */
    protected function formatOrderRowValues(PcbOrder $order): array
    {
        $getMeta = function ($key, $fallback = '') use ($order) {
            return $order->getMeta($key, $fallback);
        };

        // Order Date
        $orderDateVal = $getMeta('order_date', '');
        $orderDateStr = !empty($orderDateVal) ? Carbon::parse($orderDateVal)->format('n/j/Y') : ($order->created_at ? $order->created_at->format('n/j/Y') : '');

        // Launch Date
        $launchDateVal = $getMeta('launch_date', '');
        $launchDateStr = !empty($launchDateVal) ? Carbon::parse($launchDateVal)->format('n/j/Y') : '';

        // Delivery Date
        $deliveryDateVal = $order->delivery_date ? $order->delivery_date->format('n/j/Y') : $getMeta('delivery_date', '');

        return [
            $orderDateStr,                                                      // Col A: Order Date
            $launchDateStr,                                                     // Col B: Launch Date
            $deliveryDateVal,                                                   // Col C: Delivery date
            $getMeta('quote_number', $getMeta('q_no', '')),                     // Col D: Q# No.
            $getMeta('c_g', 'GST'),                                             // Col E: C/G
            $getMeta('tool', $order->order_number),                             // Col F: Tool
            $getMeta('combo', ''),                                             // Col G: Combo
            $order->customer_name ?: $getMeta('customer_name', ''),              // Col H: Customer name
            $getMeta('layer', $getMeta('layers', '1')),                        // Col I: Layer
            $getMeta('solder_mask', $getMeta('mask', $getMeta('pcb_color', 'Green'))), // Col J: Mask
            $order->board_name ?: $getMeta('part_number', $getMeta('p_n', '')),  // Col K: P/N
            $getMeta('production_noted', ''),                                  // Col L: Production noted
            is_numeric($getMeta('qty')) ? (int)$getMeta('qty') : (int)$getMeta('quantity', 0), // Col M: Qty
            is_numeric($getMeta('launch_qty')) ? (int)$getMeta('launch_qty') : 0,               // Col N: Launch
            is_numeric($getMeta('panel_qty')) ? (int)$getMeta('panel_qty') : 0,                 // Col O: Panel
            is_numeric($getMeta('ups')) ? (int)$getMeta('ups') : 1,                            // Col P: ups
            (int)($order->completed_qty ?? $getMeta('final_qty', 0)),           // Col Q: Final qty
            $order->status ?: $getMeta('status', 'move'),                       // Col R: status
            $getMeta('bill_number', ''),                                        // Col S: Bill number
        ];
    }
}

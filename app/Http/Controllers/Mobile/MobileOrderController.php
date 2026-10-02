<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PcbOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileOrderController extends Controller
{
    private function checkPermission(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = null;
        if ($adminId) {
            $admin = DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first();
        }
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : ['orders.view', 'inventory.view'];

        if (!in_array('orders.view', $permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Orders.'
            ], 403);
        }

        return null;
    }

    public function getStatuses(Request $request)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $statuses = [];

            if (Schema::hasTable('pcb_order_statuses')) {
                $statuses = DB::table('pcb_order_statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            } elseif (Schema::hasTable('pcb_statuses')) {
                $statuses = DB::table('pcb_statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            } elseif (Schema::hasTable('statuses')) {
                $statuses = DB::table('statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            }

            if (empty($statuses)) {
                $statuses = [
                    'In Production',
                    'Traveler',
                    'Move',
                    'Etching',
                    'HAL/Tin',
                    'DH Exposer',
                    'Outside Drill',
                    'VGroove',
                    'Etch QC',
                    'Rout Done',
                    'Silk',
                    'Final QC',
                    'Masking Exposer',
                    'FPT',
                    'Rout',
                    'Drilling',
                    'Developing',
                    'Ready to Ship',
                    'Cancelled',
                ];
            }

            $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed', 'shipped'];
            $statuses = array_values(array_filter(array_unique($statuses), function ($st) use ($completedStatuses) {
                return !in_array(strtolower(trim((string)$st)), $completedStatuses, true);
            }));

            return response()->json([
                'success' => true,
                'data' => $statuses
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $search = trim($request->input('search', ''));
            $status = trim($request->input('status', ''));
            $mask = trim($request->input('mask', ''));
            $page = max(1, (int)$request->input('page', 1));
            $perPage = min(100, max(5, (int)$request->input('per_page', 20)));

            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : null);

            $query = DB::table('pcb_orders')->whereNull('pcb_orders.deleted_at');

            // 1. In mobile, do not show completed orders at all
            $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed', 'shipped'];
            $query->where(function ($q) use ($completedStatuses) {
                $q->whereNull('pcb_orders.status')
                  ->orWhereNotIn(DB::raw('LOWER(TRIM(pcb_orders.status))'), $completedStatuses);
            });

            if ($statusTable) {
                $query->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                      ->select(
                          'pcb_orders.*',
                          "{$statusTable}.name as dynamic_status_name",
                          "{$statusTable}.slug as dynamic_status_slug"
                      )
                      ->where(function ($q) use ($completedStatuses, $statusTable) {
                          $q->whereNull("{$statusTable}.name")
                            ->orWhere(function ($sq) use ($completedStatuses, $statusTable) {
                                $sq->whereNotIn(DB::raw("LOWER(TRIM({$statusTable}.name))"), $completedStatuses)
                                   ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE({$statusTable}.slug, '')))"), $completedStatuses);
                            });
                      });
            } else {
                $query->select('pcb_orders.*');
            }

            // Hide combo member child orders from mobile production list by default (show parent & normal orders only)
            // But when user is explicitly searching ($search !== ''), include child combo orders so the searched order is found!
            if ($search === '' && Schema::hasTable('pcb_order_combos')) {
                $query->whereNotIn('pcb_orders.id', function ($subQuery) {
                    $sub = $subQuery->select('combo_order_id')->from('pcb_order_combos');
                    if (Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                        $sub->whereNull('deleted_at');
                    }
                });
            }

            if ($search !== '') {
                $cleanSearch = ltrim(trim($search), '#');
                $query->where(function ($q) use ($search, $cleanSearch, $statusTable) {
                    $hasClause = false;

                    $columnsToCheck = [
                        'order_number',
                        'pn_number',
                        'tool',
                        'combo',
                        'part_number',
                        'board_name',
                        'gerber_file_name',
                        'gerber_filename',
                        'gerber_file',
                        'file_name',
                        'gerber_name',
                        'user_email',
                        'customer_name',
                        'status',
                        'bill_number',
                    ];

                    foreach ($columnsToCheck as $col) {
                        if (Schema::hasColumn('pcb_orders', $col)) {
                            $hasClause ? $q->orWhere("pcb_orders.{$col}", 'LIKE', "%{$search}%") : $q->where("pcb_orders.{$col}", 'LIKE', "%{$search}%");
                            if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                $q->orWhere("pcb_orders.{$col}", 'LIKE', "%{$cleanSearch}%");
                            }
                            $hasClause = true;
                        }
                    }

                    if ($statusTable) {
                        $q->orWhere("{$statusTable}.name", 'LIKE', "%{$search}%");
                        if ($cleanSearch !== '' && $cleanSearch !== $search) {
                            $q->orWhere("{$statusTable}.name", 'LIKE', "%{$cleanSearch}%");
                        }
                    }

                    // Search in pcb_order_meta table for gerber file names, board names, and other metadata
                    if (Schema::hasTable('pcb_order_meta')) {
                        $metaSub = function ($sub) use ($search, $cleanSearch) {
                            $sub->select('pcb_order_id')
                                ->from('pcb_order_meta')
                                ->where(function ($mq) use ($search, $cleanSearch) {
                                    $mq->where('meta_value', 'LIKE', "%{$search}%");
                                    if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                        $mq->orWhere('meta_value', 'LIKE', "%{$cleanSearch}%");
                                    }
                                });
                        };
                        $hasClause ? $q->orWhereIn('pcb_orders.id', $metaSub) : $q->whereIn('pcb_orders.id', $metaSub);
                        $hasClause = true;
                    }

                    // Search in gerber_files table if present
                    if (Schema::hasTable('gerber_files')) {
                        if (Schema::hasColumn('pcb_orders', 'gerber_file_id')) {
                            $gerberSub = function ($sub) use ($search, $cleanSearch) {
                                $sub->select('id')
                                    ->from('gerber_files')
                                    ->where(function ($gq) use ($search, $cleanSearch) {
                                        $cols = ['original_name', 'file_name', 'filename', 'board_name'];
                                        $first = true;
                                        foreach ($cols as $c) {
                                            if (Schema::hasColumn('gerber_files', $c)) {
                                                if ($first) {
                                                    $gq->where(function ($colQ) use ($c, $search, $cleanSearch) {
                                                        $colQ->where($c, 'LIKE', "%{$search}%");
                                                        if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                                            $colQ->orWhere($c, 'LIKE', "%{$cleanSearch}%");
                                                        }
                                                    });
                                                } else {
                                                    $gq->orWhere(function ($colQ) use ($c, $search, $cleanSearch) {
                                                        $colQ->where($c, 'LIKE', "%{$search}%");
                                                        if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                                            $colQ->orWhere($c, 'LIKE', "%{$cleanSearch}%");
                                                        }
                                                    });
                                                }
                                                $first = false;
                                            }
                                        }
                                    });
                            };
                            $hasClause ? $q->orWhereIn('pcb_orders.gerber_file_id', $gerberSub) : $q->whereIn('pcb_orders.gerber_file_id', $gerberSub);
                            $hasClause = true;
                        } elseif (Schema::hasColumn('gerber_files', 'pcb_order_id')) {
                            $gerberSub = function ($sub) use ($search, $cleanSearch) {
                                $sub->select('pcb_order_id')
                                    ->from('gerber_files')
                                    ->where(function ($gq) use ($search, $cleanSearch) {
                                        $cols = ['original_name', 'file_name', 'filename', 'board_name'];
                                        $first = true;
                                        foreach ($cols as $c) {
                                            if (Schema::hasColumn('gerber_files', $c)) {
                                                if ($first) {
                                                    $gq->where(function ($colQ) use ($c, $search, $cleanSearch) {
                                                        $colQ->where($c, 'LIKE', "%{$search}%");
                                                        if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                                            $colQ->orWhere($c, 'LIKE', "%{$cleanSearch}%");
                                                        }
                                                    });
                                                } else {
                                                    $gq->orWhere(function ($colQ) use ($c, $search, $cleanSearch) {
                                                        $colQ->where($c, 'LIKE', "%{$search}%");
                                                        if ($cleanSearch !== '' && $cleanSearch !== $search) {
                                                            $colQ->orWhere($c, 'LIKE', "%{$cleanSearch}%");
                                                        }
                                                    });
                                                }
                                                $first = false;
                                            }
                                        }
                                    });
                            };
                            $hasClause ? $q->orWhereIn('pcb_orders.id', $gerberSub) : $q->whereIn('pcb_orders.id', $gerberSub);
                            $hasClause = true;
                        }
                    }
                });
            }

            // Status filtering: When searching, search ONLY in the selected status!
            $cleanStatus = trim($status);
            $statusLower = strtolower($cleanStatus);
            $normStatus = strtolower(str_replace(['/', '-', '_', ' '], '', $cleanStatus));
            if ($normStatus === 'devloping') $normStatus = 'developing';
            if ($normStatus === 'canceled') $normStatus = 'cancelled';

            if ($statusLower === 'all' || $statusLower === 'all_statuses') {
                // When explicitly requested 'all', view/search across all statuses (except completed which is excluded globally)
            } elseif ($statusLower === '' || $statusLower === 'in_production' || $statusLower === 'in production') {
                // Admin Panel definition of "In Production": exclude pending, completed, shipped, delivered, cancelled, etc.
                // When searching in 'in_production', search ONLY within in_production orders!
                $excluded = ['pending', 'completed', 'shipped', 'delivered', 'cancelled', 'canceled', 'archived'];

                $query->where(function ($q) use ($excluded, $statusTable) {
                    $q->where(function ($sq) use ($excluded) {
                        $sq->whereNotNull('pcb_orders.status')
                           ->whereNotIn(DB::raw('LOWER(TRIM(pcb_orders.status))'), $excluded);
                    });

                    if ($statusTable) {
                        $q->orWhere(function ($sq) use ($excluded, $statusTable) {
                            $sq->whereNotNull("{$statusTable}.name")
                               ->whereNotIn(DB::raw("LOWER(TRIM({$statusTable}.name))"), $excluded)
                               ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE({$statusTable}.slug, '')))"), $excluded);
                        });
                    }
                });
            } else {
                // Specific status requested (e.g. "Pending", "Traveler", "Drilling", "Outside Drill", etc.)
                // When searching, only search within this selected status!
                $query->where(function ($q) use ($statusLower, $cleanStatus, $normStatus, $statusTable) {
                    $q->where(DB::raw('LOWER(TRIM(COALESCE(pcb_orders.status, "")))'), $statusLower)
                      ->orWhere('pcb_orders.status', 'LIKE', "%{$cleanStatus}%")
                      ->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(pcb_orders.status, ''), ' ', ''), '-', ''), '_', ''), '/', '')) = ?", [$normStatus]);

                    if ($statusTable) {
                        $q->orWhere(function ($sq) use ($statusLower, $cleanStatus, $normStatus, $statusTable) {
                            $sq->whereNotNull("{$statusTable}.name")
                               ->where(function ($ssq) use ($statusLower, $cleanStatus, $normStatus, $statusTable) {
                                   $ssq->where(DB::raw("LOWER(TRIM({$statusTable}.name))"), $statusLower)
                                       ->orWhere("{$statusTable}.name", 'LIKE', "%{$cleanStatus}%")
                                       ->orWhere(DB::raw("LOWER(TRIM(COALESCE({$statusTable}.slug, '')))"), $statusLower)
                                       ->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$statusTable}.name, ''), ' ', ''), '-', ''), '_', ''), '/', '')) = ?", [$normStatus]);
                               });
                        });
                    }
                });
            }

            $total = $query->count();

            // Default sort: order by order date (created_at desc) same as admin
            $sortBy = trim((string)$request->input('sort_by', 'created_at'));
            $sortOrder = strtolower(trim((string)$request->input('sort_order', 'desc'))) === 'asc' ? 'asc' : 'desc';

            // When searching, prioritize exact order_number / tool match to appear at the top
            if ($search !== '') {
                $cleanSearchLower = strtolower(ltrim(trim($search), '#'));
                $hasToolCol = Schema::hasColumn('pcb_orders', 'tool');
                if ($hasToolCol) {
                    $query->orderByRaw(
                        "CASE 
                            WHEN LOWER(TRIM(pcb_orders.order_number)) = ? OR LOWER(TRIM(pcb_orders.order_number)) = ? OR LOWER(TRIM(pcb_orders.tool)) = ? THEN 1
                            WHEN LOWER(TRIM(pcb_orders.order_number)) LIKE ? OR LOWER(TRIM(pcb_orders.tool)) LIKE ? THEN 2
                            ELSE 3
                        END ASC",
                        [$cleanSearchLower, '#' . $cleanSearchLower, $cleanSearchLower, $cleanSearchLower . '%', $cleanSearchLower . '%']
                    );
                } else {
                    $query->orderByRaw(
                        "CASE 
                            WHEN LOWER(TRIM(pcb_orders.order_number)) = ? OR LOWER(TRIM(pcb_orders.order_number)) = ? THEN 1
                            WHEN LOWER(TRIM(pcb_orders.order_number)) LIKE ? THEN 2
                            ELSE 3
                        END ASC",
                        [$cleanSearchLower, '#' . $cleanSearchLower, $cleanSearchLower . '%']
                    );
                }
            }

            // Order by: default is created_at desc (order date) same as admin
            if ($sortBy === 'delivery_date') {
                $query->orderByRaw("COALESCE(pcb_orders.delivery_date, pcb_orders.created_at) {$sortOrder}")
                      ->orderBy('pcb_orders.created_at', 'desc')
                      ->orderBy('pcb_orders.id', 'desc');
            } elseif ($sortBy === 'created_at' || $sortBy === 'order_date') {
                $query->orderBy('pcb_orders.created_at', $sortOrder)
                      ->orderBy('pcb_orders.id', $sortOrder);
            } else {
                if (Schema::hasColumn('pcb_orders', $sortBy)) {
                    $query->orderBy('pcb_orders.' . $sortBy, $sortOrder)
                          ->orderBy('pcb_orders.id', 'desc');
                } else {
                    $query->orderBy('pcb_orders.created_at', $sortOrder)
                          ->orderBy('pcb_orders.id', $sortOrder);
                }
            }

            $orders = $query
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            $today = date('Y-m-d');

            // Pre-load child-to-parent combo relationships for current page orders
            $childToParentMap = [];
            if (Schema::hasTable('pcb_order_combos') && $orders->isNotEmpty()) {
                $orderIds = $orders->pluck('id')->toArray();
                $childToParentMap = DB::table('pcb_order_combos')
                    ->join('pcb_orders', 'pcb_order_combos.parent_order_id', '=', 'pcb_orders.id')
                    ->whereIn('pcb_order_combos.combo_order_id', $orderIds)
                    ->when(Schema::hasColumn('pcb_order_combos', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_combos.deleted_at'))
                    ->select('pcb_order_combos.combo_order_id', 'pcb_orders.order_number')
                    ->distinct()
                    ->get()
                    ->groupBy('combo_order_id')
                    ->map(function ($rows) {
                        return $rows->pluck('order_number')->filter()->unique()->implode(', ');
                    })
                    ->toArray();
            }

            $items = $orders->map(function ($order) use ($today, $childToParentMap) {
                $dueDateStr = 'Upcoming';
                $delDate = $order->delivery_date ?? null;
                if ($delDate) {
                    if ($delDate === $today) {
                        $dueDateStr = 'Today';
                    } else {
                        $dueDateStr = date('d M Y', strtotime($delDate));
                    }
                }

                $metaMap = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key')
                    ->toArray();

                $orderQty = (int) ($order->order_qty ?? $order->quantity ?? $metaMap['quantity'] ?? $metaMap['order_qty'] ?? 50);
                $launchQty = (int) ($order->launch_qty ?? $metaMap['launch_qty'] ?? $orderQty);
                $panelQty = (int) ($order->panel_qty ?? $order->panel ?? $metaMap['panel_qty'] ?? $metaMap['panel'] ?? 0);
                $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
                $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
                $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

                $gerberFileName = $order->board_name
                    ?? $order->gerber_file_name
                    ?? $order->gerber_filename
                    ?? $order->gerber_file
                    ?? $order->file_name
                    ?? $metaMap['gerber_file_name']
                    ?? $metaMap['gerber_filename']
                    ?? $metaMap['gerber_file']
                    ?? $metaMap['board_name']
                    ?? $metaMap['file_name']
                    ?? null;

                $displayStatus = (!empty($order->dynamic_status_name))
                    ? $order->dynamic_status_name
                    : (!empty($order->status) ? $order->status : 'Pending');

                $metaMapLower = [];
                foreach ($metaMap as $k => $v) {
                    $metaMapLower[strtolower(trim((string)$k))] = $v;
                }

                $customerNote = $metaMapLower['pcb_remark']
                    ?? $metaMapLower['customer_remark']
                    ?? $metaMapLower['customer_note']
                    ?? $metaMapLower['customer_notes']
                    ?? $metaMapLower['remarks']
                    ?? $metaMapLower['remark']
                    ?? $metaMapLower['special_instructions']
                    ?? $metaMapLower['customer_instructions']
                    ?? ($order->customer_remark ?? null)
                    ?? ($order->remarks ?? null)
                    ?? ($order->pcb_remark ?? null)
                    ?? ($order->remark ?? null)
                    ?? null;

                if ($customerNote !== null) {
                    $customerNote = trim((string)$customerNote);
                    if (strtoupper($customerNote) === 'N/A' || $customerNote === '') {
                        $customerNote = null;
                    }
                }

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'order_number' => $order->order_number ?? ('M' . $order->id),
                    'status' => ucfirst($displayStatus),
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'pn_number' => $order->pn_number ?? $metaMap['pn_number'] ?? $metaMap['p_n'] ?? $metaMap['part_number'] ?? null,
                    'customer_notes' => $customerNote,
                    'customer_note' => $customerNote,
                    'pcb_remark' => $customerNote,
                    'customer_remark' => $customerNote,
                    'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                    'gerber_file_name' => $gerberFileName,
                    'board_name' => $order->board_name ?? $metaMap['board_name'] ?? $gerberFileName,
                    'department' => $metaMap['department'] ?? 'Production',
                    'priority' => $metaMap['priority'] ?? 'Normal',
                    'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                    'dueDate' => $dueDateStr,
                    'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                    'maskColor' => $this->resolveMaskColor($order, $metaMap),
                    'layers' => $this->resolveLayers($order, $metaMap),
                    'quantity' => $orderQty,
                    'launchQty' => $launchQty,
                    'panel' => $panelQty,
                    'panelQty' => $panelQty,
                    'finalQty' => $finalQty,
                    'failedQty' => $failedQty,
                    'pendingQty' => $pendingQty,
                    'combo' => $order->combo ?? null,
                    'combo_parent' => $childToParentMap[$order->id] ?? null,
                    'lastUpdate' => ($order->updated_at ?? null) ? date('h:i A', strtotime($order->updated_at)) : 'Just now'
                ];
            });

            if ($mask !== '') {
                $items = $items->filter(function ($item) use ($mask) {
                    return strtolower($item['maskColor']) === strtolower($mask);
                })->values();
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int)ceil($total / $perPage))
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : null);

            $orderQuery = DB::table('pcb_orders')->whereNull('pcb_orders.deleted_at');
            if ($statusTable) {
                $orderQuery->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                           ->select('pcb_orders.*', "{$statusTable}.name as dynamic_status_name");
            } else {
                $orderQuery->select('pcb_orders.*');
            }

            $order = $orderQuery->where(function($q) use ($id) {
                $q->where('pcb_orders.id', $id)
                  ->orWhere('pcb_orders.order_number', $id);
            })->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $metaMap = DB::table('pcb_order_meta')
                ->where('pcb_order_id', $order->id)
                ->pluck('meta_value', 'meta_key')
                ->toArray();

            $today = date('Y-m-d');
            $delDate = $order->delivery_date ?? null;
            $dueDateStr = $delDate === $today ? 'Today' : ($delDate ? date('d M Y', strtotime($delDate)) : 'Upcoming');

            // Fetch order history logs
            $history = [];
            if (Schema::hasTable('pcb_order_logs')) {
                $logs = DB::table('pcb_order_logs')
                    ->where('pcb_order_id', $order->id)
                    ->orWhere('order_number', $order->order_number ?? '')
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $logs->map(function ($log) {
                    return [
                        'id' => (string) $log->id,
                        'status' => $log->status ?? 'Updated',
                        'action' => $log->action ?? 'Status Change',
                        'description' => $log->description ?? '',
                        'created_at' => $log->created_at ? date('d M Y · h:i A', strtotime($log->created_at)) : '',
                    ];
                })->toArray();
            } elseif (Schema::hasTable('pcb_order_status_histories')) {
                $histories = DB::table('pcb_order_status_histories')
                    ->where('pcb_order_id', $order->id)
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $histories->map(function ($h) {
                    return [
                        'id' => (string) $h->id,
                        'status' => $h->status_name ?? 'Updated',
                        'action' => 'Status Updated',
                        'description' => $h->remark ?? ('Status updated to ' . ($h->status_name ?? '')),
                        'created_at' => $h->created_at ? date('d M Y · h:i A', strtotime($h->created_at)) : '',
                    ];
                })->toArray();
            }

            $orderQty = (int) ($order->order_qty ?? $order->quantity ?? $metaMap['quantity'] ?? $metaMap['order_qty'] ?? 50);
            $launchQty = (int) ($order->launch_qty ?? $metaMap['launch_qty'] ?? $orderQty);
            $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
            $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
            $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

            $displayStatus = (!empty($order->dynamic_status_name))
                ? $order->dynamic_status_name
                : (!empty($order->status) ? $order->status : 'Pending');

            $actualGerber = null;
            if (!empty($order->gerber_file_id) && Schema::hasTable('gerber_files')) {
                $gf = DB::table('gerber_files')->where('id', $order->gerber_file_id)->first();
                if ($gf) {
                    $actualGerber = [
                        'id' => $gf->id,
                        'original_name' => $gf->original_name,
                        'file_name' => $gf->file_name ?? $gf->original_name,
                        'file_url' => $gf->file_url ?? null,
                    ];
                }
            }

            $metaMapLower = [];
            foreach ($metaMap as $k => $v) {
                $metaMapLower[strtolower(trim((string)$k))] = $v;
            }

            $customerNote = $metaMapLower['pcb_remark']
                ?? $metaMapLower['customer_remark']
                ?? $metaMapLower['customer_note']
                ?? $metaMapLower['customer_notes']
                ?? $metaMapLower['remarks']
                ?? $metaMapLower['remark']
                ?? $metaMapLower['special_instructions']
                ?? $metaMapLower['customer_instructions']
                ?? ($order->customer_remark ?? null)
                ?? ($order->remarks ?? null)
                ?? ($order->pcb_remark ?? null)
                ?? ($order->remark ?? null)
                ?? null;

            if ($customerNote !== null) {
                $customerNote = trim((string)$customerNote);
                if (strtoupper($customerNote) === 'N/A' || $customerNote === '') {
                    $customerNote = null;
                }
            }

            $comboOrders = Schema::hasTable('pcb_order_combos')
                ? DB::table('pcb_order_combos')
                    ->join('pcb_orders', 'pcb_order_combos.combo_order_id', '=', 'pcb_orders.id')
                    ->where('pcb_order_combos.parent_order_id', $order->id)
                    ->when(Schema::hasColumn('pcb_order_combos', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_combos.deleted_at'))
                    ->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')
                    ->distinct()
                    ->get()
                : [];
            $comboOrderIds = Schema::hasTable('pcb_order_combos')
                ? DB::table('pcb_order_combos')
                    ->where('parent_order_id', $order->id)
                    ->when(Schema::hasColumn('pcb_order_combos', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_combos.deleted_at'))
                    ->pluck('combo_order_id')
                    ->unique()
                    ->values()
                    ->toArray()
                : [];

            $oldOrders = Schema::hasTable('pcb_order_old_orders')
                ? DB::table('pcb_order_old_orders')
                    ->join('pcb_orders', 'pcb_order_old_orders.old_order_id', '=', 'pcb_orders.id')
                    ->where('pcb_order_old_orders.order_id', $order->id)
                    ->when(Schema::hasColumn('pcb_order_old_orders', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_old_orders.deleted_at'))
                    ->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')
                    ->distinct()
                    ->get()
                : [];
            $oldOrderIds = Schema::hasTable('pcb_order_old_orders')
                ? DB::table('pcb_order_old_orders')
                    ->where('order_id', $order->id)
                    ->when(Schema::hasColumn('pcb_order_old_orders', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_old_orders.deleted_at'))
                    ->pluck('old_order_id')
                    ->unique()
                    ->values()
                    ->toArray()
                : [];

            $data = [
                'id' => (string) $order->id,
                'tool' => $order->order_number ?? ('M' . $order->id),
                'order_number' => $order->order_number ?? ('M' . $order->id),
                'orderNumber' => $order->order_number ?? ('M' . $order->id),
                'status' => ucfirst($displayStatus),
                'raw_status' => $displayStatus,
                'status_id' => $order->status_id ?? null,
                'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                'pn_number' => $order->pn_number ?? $metaMap['pn_number'] ?? $metaMap['p_n'] ?? $metaMap['part_number'] ?? null,
                'c_g' => $order->c_g ?? null,
                'q_no' => $order->q_no ?? null,
                'bill_number' => $order->bill_number ?? null,
                'delivery_date' => $order->delivery_date ?? null,
                'customer_name' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? null),
                'user_id' => $order->user_id ?? null,
                'customer_notes' => $customerNote,
                'customer_note' => $customerNote,
                'pcb_remark' => $customerNote,
                'customer_remark' => $customerNote,
                'gerber_file' => $actualGerber,
                'has_gerber' => $actualGerber !== null,
                'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                'department' => $metaMap['department'] ?? 'Production',
                'priority' => $metaMap['priority'] ?? 'Normal',
                'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                'dueDate' => $dueDateStr,
                'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                'maskColor' => $this->resolveMaskColor($order, $metaMap),
                'layers' => $this->resolveLayers($order, $metaMap),
                'quantity' => $orderQty,
                'order_qty' => $orderQty,
                'launchQty' => $launchQty,
                'launch_qty' => $launchQty,
                'panel_qty' => (int)($order->panel_qty ?? $metaMap['panel_qty'] ?? 0),
                'ups_qty' => (int)($order->ups_qty ?? $metaMap['ups_qty'] ?? 0),
                'finalQty' => $finalQty,
                'final_qty' => $finalQty,
                'failedQty' => $failedQty,
                'failed_qty' => $failedQty,
                'completed_qty' => (int)($order->completed_qty ?? $finalQty),
                'pendingQty' => $pendingQty,
                'combo' => $order->combo ?? null,
                'combo_orders' => $comboOrders,
                'combo_order_ids' => $comboOrderIds,
                'combo_parent' => Schema::hasTable('pcb_order_combos')
                    ? DB::table('pcb_order_combos')
                        ->join('pcb_orders', 'pcb_order_combos.parent_order_id', '=', 'pcb_orders.id')
                        ->where('pcb_order_combos.combo_order_id', $order->id)
                        ->when(Schema::hasColumn('pcb_order_combos', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_combos.deleted_at'))
                        ->pluck('pcb_orders.order_number')
                        ->filter()
                        ->unique()
                        ->implode(', ')
                    : null,
                'parent_combo_orders' => Schema::hasTable('pcb_order_combos')
                    ? DB::table('pcb_order_combos')
                        ->join('pcb_orders', 'pcb_order_combos.parent_order_id', '=', 'pcb_orders.id')
                        ->where('pcb_order_combos.combo_order_id', $order->id)
                        ->when(Schema::hasColumn('pcb_order_combos', 'deleted_at'), fn($q) => $q->whereNull('pcb_order_combos.deleted_at'))
                        ->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')
                        ->distinct()
                        ->get()
                    : [],
                'old_order_number' => $order->old_order_number ?? null,
                'old_orders' => $oldOrders,
                'old_order_ids' => $oldOrderIds,
                'lastUpdate' => ($order->updated_at ?? null) ? date('h:i A', strtotime($order->updated_at)) : 'Just now',
                'user_email' => $order->user_email ?? '',
                'user_mobile' => $order->user_mobile ?? '',
                'unit_price' => (float)($order->unit_price ?? 0),
                'order_value' => (float)($order->order_value ?? 0),
                'history' => $history,
                'metas' => $metaMap,
                'base_material' => $metaMap['base_material'] ?? $metaMap['material'] ?? 'FR-4',
                'substrate_type' => $metaMap['substrate_type'] ?? 'N/A',
                'material_type' => $metaMap['material_type'] ?? 'FR4-TG135',
                'thickness' => $metaMap['thickness'] ?? $metaMap['board_thickness'] ?? '1.6mm',
                'solder_mask' => $this->resolveMaskColor($order, $metaMap),
                'silkscreen' => $metaMap['silkscreen'] ?? $metaMap['silkscreen_color'] ?? 'White',
                'surface_finish' => $metaMap['surface_finish'] ?? $metaMap['finish'] ?? 'HASL(Leaded)',
                'gold_thickness' => $metaMap['gold_thickness'] ?? 'N/A',
                'copper_weight' => $metaMap['copper_weight'] ?? $metaMap['copper_thickness'] ?? '1 oz',
                'via_covering' => $metaMap['via_covering'] ?? 'N/A',
                'via_plating' => $metaMap['via_plating'] ?? 'N/A',
                'min_hole' => $metaMap['min_hole'] ?? $metaMap['min_hole_size'] ?? 'N/A',
                'confirm_file' => $metaMap['confirm_file'] ?? 'No',
                'mark_on_pcb' => $metaMap['mark_on_pcb'] ?? 'Remove Mark',
                'elec_test' => $metaMap['elec_test'] ?? 'Flying Probe Fully Test',
                'different_design' => $metaMap['different_design'] ?? '1',
                'delivery_format' => $metaMap['delivery_format'] ?? 'Single PCB',
                'panel_format' => $metaMap['panel_format'] ?? 'N/A',
                'coverlay_thickness' => $metaMap['coverlay_thickness'] ?? 'N/A',
                'stiffener' => $metaMap['stiffener'] ?? 'N/A',
                'emi_shielding' => $metaMap['emi_shielding'] ?? 'N/A',
                'dimensions' => $metaMap['dimensions'] ?? null,
                'gold_fingers' => $metaMap['gold_fingers'] ?? 'No',
                'castellated' => $metaMap['castellated'] ?? 'No',
                'edge_plating' => $metaMap['edge_plating'] ?? 'No',
                'blind_slots' => $metaMap['blind_slots'] ?? 'No',
                'ul_marking' => $metaMap['ul_marking'] ?? 'No',
                'humidity' => $metaMap['humidity'] ?? 'No',
                'kelvin_test' => $metaMap['kelvin_test'] ?? 'No',
                'paper_between' => $metaMap['paper_between'] ?? 'No',
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getHistory(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')
                ->where('id', $id)
                ->orWhere('order_number', $id)
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $history = [];
            if (Schema::hasTable('pcb_order_logs')) {
                $logs = DB::table('pcb_order_logs')
                    ->where('pcb_order_id', $order->id)
                    ->orWhere('order_number', $order->order_number ?? '')
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $logs->map(function ($log) {
                    return [
                        'id' => (string) $log->id,
                        'status' => $log->status ?? 'Updated',
                        'action' => $log->action ?? 'Status Change',
                        'description' => $log->description ?? '',
                        'created_at' => $log->created_at ? date('d M Y · h:i A', strtotime($log->created_at)) : '',
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        DB::beginTransaction();
        try {
            $newStatus = trim((string)$request->input('status'));
            if (empty($newStatus)) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Status is required'], 400);
            }

            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $oldStatus = $order->status ?? 'Pending';
            $oldValStr = strtolower(trim((string)$oldStatus));
            $newValStr = strtolower(trim((string)$newStatus));
            $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed'];

            $isCompleted = in_array($newValStr, $completedStatuses);
            $inputBillNumber = $request->has('bill_number') ? trim((string)$request->input('bill_number')) : null;
            $effectiveBillNumber = $inputBillNumber !== null ? $inputBillNumber : trim((string)($order->bill_number ?? ''));

            if ($isCompleted && $effectiveBillNumber === '') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Bill number is required before changing the order status to Completed.',
                    'errors'  => [
                        'bill_number' => [
                            'Bill number is required when completing an order.'
                        ]
                    ]
                ], 422);
            }

            $updateData = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : null);
            if ($statusTable) {
                $matchingStatus = DB::table($statusTable)
                    ->where(DB::raw('LOWER(TRIM(name))'), $newValStr)
                    ->orWhere(DB::raw('LOWER(TRIM(slug))'), $newValStr)
                    ->first();
                if ($matchingStatus) {
                    $updateData['status_id'] = $matchingStatus->id;
                }
            }

            if ($inputBillNumber !== null) {
                $updateData['bill_number'] = $inputBillNumber !== '' ? $inputBillNumber : null;
            }

            DB::table('pcb_orders')->where('id', $id)->update($updateData);

            // Dispatch order_status_updated email notification when status changes (previous != new)
            if ($oldValStr !== $newValStr) {
                \App\Services\EmailTemplateService::sendOrderEmail('order_status_updated', $id, null, [
                    'previous_order_status' => $oldStatus,
                ]);
            }

            // Dispatch order_production_film_not_applied email if transition was Pending -> Non-Pending and film_applied != 1
            if ($oldValStr === 'pending' && $newValStr !== 'pending') {
                $freshFilmApplied = DB::table('pcb_orders')->where('id', $id)->value('film_applied');
                if ((int)$freshFilmApplied !== 1) {
                    \App\Services\EmailTemplateService::sendOrderEmail('order_production_film_not_applied', $id, null, [
                        'previous_order_status' => $oldStatus,
                        'film_applied'          => 'No',
                    ]);
                }
            }

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';

            // Insert into pcb_order_logs
            if (Schema::hasTable('pcb_order_logs')) {
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $newStatus,
                    'action' => "Status Updated: {$newStatus}",
                    'description' => "Order status updated from '{$oldStatus}' to '{$newStatus}' by {$adminName}.",
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            if (Schema::hasTable('pcb_order_status_histories')) {
                DB::table('pcb_order_status_histories')->insert([
                    'pcb_order_id' => $order->id,
                    'admin_id' => $adminId ?: 1,
                    'status_name' => $newStatus,
                    'remark' => "Status updated to '{$newStatus}' by {$adminName}",
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Synchronize status to child combo member orders if this order is a parent
            $parentPcbOrder = \App\Models\PcbOrder::find($order->id);
            if ($parentPcbOrder) {
                \App\Services\ComboOrderService::syncComboStatus($parentPcbOrder, $newStatus, (int)($adminId ?: 1), $adminName);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'status' => $newStatus,
                    'bill_number' => $effectiveBillNumber !== '' ? $effectiveBillNumber : null
                ]
            ]);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function getNotes(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->orWhere('order_number', $id)->first();
            $orderId = $order ? $order->id : $id;

            $notes = [];
            if (Schema::hasTable('pcb_order_notes')) {
                // Ensure created_by column exists
                if (!Schema::hasColumn('pcb_order_notes', 'created_by')) {
                    try {
                        Schema::table('pcb_order_notes', function (\Illuminate\Database\Schema\Blueprint $table) {
                            $table->unsignedBigInteger('created_by')->nullable()->after('admin_id');
                        });
                        DB::statement("UPDATE pcb_order_notes SET created_by = admin_id WHERE created_by IS NULL AND admin_id IS NOT NULL");
                    } catch (\Throwable $e) {}
                }

                $notesQuery = DB::table('pcb_order_notes')
                    ->where('pcb_order_notes.pcb_order_id', $orderId);

                if (Schema::hasTable('admins')) {
                    $notesQuery->leftJoin('admins', function($join) {
                        $join->on('admins.id', '=', DB::raw('COALESCE(pcb_order_notes.created_by, pcb_order_notes.admin_id)'));
                    });
                }

                if (Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    $notesQuery->whereNull('pcb_order_notes.deleted_at');
                }

                $adminNameSql = 'COALESCE(admins.name, admins.username, "Admin")';

                // Fallback to active admin from order logs
                $logAdminName = null;
                if ($order && Schema::hasTable('pcb_order_logs')) {
                    $latestLog = DB::table('pcb_order_logs')
                        ->where(function($q) use ($order) {
                            $q->where('pcb_order_id', $order->id)->orWhere('order_number', $order->order_number);
                        });
                    if (Schema::hasTable('admins')) {
                        $latestLog->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id');
                    }
                    $logRow = $latestLog->whereNotNull('admins.name')->select('admins.name')->latest('pcb_order_logs.id')->first();
                    if ($logRow && !empty($logRow->name)) {
                        $logAdminName = $logRow->name;
                    }
                }
                if (!$logAdminName && Schema::hasTable('admins')) {
                    $firstAdm = DB::table('admins')->where('id', '>', 0)->orderBy('id')->first();
                    if ($firstAdm) $logAdminName = $firstAdm->name ?? ($firstAdm->username ?? 'Admin');
                }

                $notes = $notesQuery->orderBy('pcb_order_notes.id', 'desc')
                    ->select(
                        'pcb_order_notes.id',
                        'pcb_order_notes.note',
                        'pcb_order_notes.created_at',
                        DB::raw("{$adminNameSql} as admin_name")
                    )
                    ->get()
                    ->map(function ($n) use ($logAdminName) {
                        $author = $n->admin_name ?: ($logAdminName ?: 'Admin');
                        return [
                            'id' => (string) $n->id,
                            'note' => $n->note,
                            'name' => $author,
                            'admin_name' => $author,
                            'created_at' => ($n->created_at ?? null) ? date('d M Y, h:i A', strtotime($n->created_at)) : ''
                        ];
                    });
            }

            // Also retrieve customer remark / instruction for convenient access
            $customerNote = null;
            if ($order) {
                $metaMap = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key')
                    ->toArray();

                $metaMapLower = [];
                foreach ($metaMap as $k => $v) {
                    $metaMapLower[strtolower(trim((string)$k))] = $v;
                }

                $customerNote = $metaMapLower['pcb_remark']
                    ?? $metaMapLower['customer_remark']
                    ?? $metaMapLower['customer_note']
                    ?? $metaMapLower['customer_notes']
                    ?? $metaMapLower['remarks']
                    ?? $metaMapLower['remark']
                    ?? $metaMapLower['special_instructions']
                    ?? $metaMapLower['customer_instructions']
                    ?? ($order->customer_remark ?? null)
                    ?? ($order->remarks ?? null)
                    ?? ($order->pcb_remark ?? null)
                    ?? ($order->remark ?? null)
                    ?? null;

                if ($customerNote !== null) {
                    $customerNote = trim((string)$customerNote);
                    if (strtoupper($customerNote) === 'N/A' || $customerNote === '') {
                        $customerNote = null;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $notes,
                'customer_notes' => $customerNote,
                'pcb_remark' => $customerNote,
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function addNote(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $note = trim($request->input('note', ''));
            if (empty($note)) {
                return response()->json(['success' => false, 'message' => 'Note content is required'], 400);
            }

            $order = DB::table('pcb_orders')->where('id', $id)->orWhere('order_number', $id)->first();
            $orderId = $order ? $order->id : $id;
            $adminId = $request->input('created_by') ?: ($request->input('admin_id') ?: ($request->attributes->get('admin_id') ?: 1));

            if (Schema::hasTable('pcb_order_notes')) {
                // Ensure created_by column exists
                if (!Schema::hasColumn('pcb_order_notes', 'created_by')) {
                    try {
                        Schema::table('pcb_order_notes', function (\Illuminate\Database\Schema\Blueprint $table) {
                            $table->unsignedBigInteger('created_by')->nullable()->after('admin_id');
                        });
                    } catch (\Throwable $e) {}
                }

                $insertData = [
                    'pcb_order_id' => $orderId,
                    'admin_id' => $adminId,
                    'note' => $note,
                    'is_internal' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                if (Schema::hasColumn('pcb_order_notes', 'created_by')) {
                    $insertData['created_by'] = $adminId;
                }

                $noteId = DB::table('pcb_order_notes')->insertGetId($insertData);

                // Query admin name from admins table
                $adminName = null;
                if (Schema::hasTable('admins')) {
                    $adm = DB::table('admins')->where('id', $adminId)->first();
                    if ($adm) {
                        $adminName = $adm->name ?? $adm->username;
                    }
                }
                if (!$adminName && Schema::hasTable('users')) {
                    $u = DB::table('users')->where('id', $adminId)->first();
                    if ($u) {
                        $adminName = $u->name ?? $u->username;
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Note added successfully',
                    'data' => [
                        'id' => (string) $noteId,
                        'pcb_order_id' => $orderId,
                        'created_by' => $adminId,
                        'admin_id' => $adminId,
                        'admin_name' => $adminName ?: 'Admin',
                        'name' => $adminName ?: 'Admin',
                        'note' => $note,
                        'created_at' => date('d M Y, h:i A')
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Note added successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deleteNote(Request $request, $noteId)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            if (Schema::hasTable('pcb_order_notes')) {
                if (Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    DB::table('pcb_order_notes')
                        ->where('id', $noteId)
                        ->update(['deleted_at' => date('Y-m-d H:i:s')]);
                } else {
                    DB::table('pcb_order_notes')->where('id', $noteId)->delete();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateQuantities(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';

            $now = date('Y-m-d H:i:s');
            $updatedFields = [];
            $logMessages = [];

            // 1. Launch Qty
            if ($request->has('launch_qty')) {
                $newVal = (int) $request->input('launch_qty');
                $oldVal = (int) ($order->launch_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'launch_qty')) {
                        $updatedFields['launch_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'launch_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Launched Qty: {$oldVal} → {$newVal}";
                }
            }

            // 2. Final / Completed Qty
            if ($request->has('final_qty')) {
                $newVal = (int) $request->input('final_qty');
                $oldVal = (int) ($order->final_qty ?? $order->completed_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'final_qty')) {
                        $updatedFields['final_qty'] = $newVal;
                    }
                    if (Schema::hasColumn('pcb_orders', 'completed_qty')) {
                        $updatedFields['completed_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'final_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Final Qty: {$oldVal} → {$newVal}";
                }
            }

            // 3. Failed Qty
            if ($request->has('failed_qty')) {
                $newVal = (int) $request->input('failed_qty');
                $oldVal = (int) ($order->failed_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'failed_qty')) {
                        $updatedFields['failed_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'failed_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Failed Qty: {$oldVal} → {$newVal}";
                }
            }

            if (!empty($updatedFields)) {
                $updatedFields['updated_at'] = $now;
                DB::table('pcb_orders')->where('id', $id)->update($updatedFields);
            }

            // Write to pcb_order_logs audit log
            if (!empty($logMessages) && Schema::hasTable('pcb_order_logs')) {
                $desc = "Quantities updated by {$adminName}: " . implode(', ', $logMessages);
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $order->status ?? 'Production',
                    'action' => 'Quantities Updated',
                    'description' => $desc,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order quantities updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateFilmApplied(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->orWhere('order_number', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $filmApplied = filter_var($request->input('film_applied'), FILTER_VALIDATE_BOOLEAN);
            $now = date('Y-m-d H:i:s');

            $updatedFields = ['updated_at' => $now];
            if (Schema::hasColumn('pcb_orders', 'film_applied')) {
                $updatedFields['film_applied'] = $filmApplied ? 1 : 0;
            }

            DB::table('pcb_orders')->where('id', $order->id)->update($updatedFields);

            DB::table('pcb_order_meta')->updateOrInsert(
                ['pcb_order_id' => $order->id, 'meta_key' => 'film_applied'],
                ['meta_value' => $filmApplied ? '1' : '0', 'updated_at' => $now]
            );

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';
            $statusStr = $filmApplied ? 'True (Applied)' : 'False (Not Applied)';

            if (Schema::hasTable('pcb_order_logs')) {
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $order->status ?? 'Production',
                    'action' => 'Film Applied Updated',
                    'description' => "Film Applied marked as {$statusStr} by {$adminName}.",
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Film applied status updated successfully',
                'film_applied' => $filmApplied
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    private function resolveLayers($order, array $metaMap): int
    {
        $raw = $order->layers ?? $metaMap['layers'] ?? $metaMap['layer'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (is_numeric($raw)) {
                return max(1, (int)$raw);
            }
            if (preg_match('/(\d+)/', (string)$raw, $matches)) {
                return max(1, (int)$matches[1]);
            }
        }
        return 2;
    }

    private function resolveMaskColor($order, array $metaMap): string
    {
        $raw = $order->mask ?? $metaMap['mask_color'] ?? $metaMap['pcb_color'] ?? $metaMap['solder_mask'] ?? $metaMap['mask'] ?? $metaMap['coverlay_color'] ?? null;
        return ($raw !== null && trim((string)$raw) !== '') ? (string)$raw : 'Green';
    }

    public function store(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('orders.create', $permissions) && !in_array('orders.manage', $permissions)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create orders.'], 403);
        }

        try {
            $customerName = trim($request->input('customer_name', $request->input('client', 'Apex Controls')));
            $boardName = trim($request->input('board_name', $request->input('boardName', 'PCB Design')));
            $quantity = (int) $request->input('quantity', 100);
            $status = trim($request->input('status', 'In Production'));

            $orderId = DB::table('pcb_orders')->insertGetId([
                'customer_name' => $customerName,
                'board_name' => $boardName,
                'order_qty' => $quantity,
                'launch_qty' => $quantity,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $orderNumber = 'M' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
            DB::table('pcb_orders')->where('id', $orderId)->update(['order_number' => $orderNumber]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'id' => (string)$orderId,
                    'order_number' => $orderNumber,
                    'customer_name' => $customerName,
                    'board_name' => $boardName,
                    'quantity' => $quantity,
                    'status' => $status
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('orders.edit', $permissions) && !in_array('orders.manage', $permissions)) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'You do not have permission to edit orders.'
            ], 403);
        }

        try {
            // Forward request to the shared OrderController update method with mobile source tag
            $request->merge(['source' => 'mobile']);
            $orderController = app(\App\Http\Controllers\OrderController::class);
            $response = $orderController->update($request, $id);

            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                if (!isset($content['success'])) {
                    $content['success'] = !empty($content['status']);
                }
                return response()->json($content, $response->getStatusCode());
            }

            return $response;
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('orders.delete', $permissions) && !in_array('orders.manage', $permissions)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete orders.'], 403);
        }

        try {
            $order = PcbOrder::withTrashed()->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            if ($order->trashed()) {
                return response()->json(['success' => false, 'message' => 'Order is already deleted.'], 404);
            }

            $orderNumber = $order->order_number;
            $orderId = $order->id;

            DB::beginTransaction();

            // Execute Soft Delete (triggers model boot deleting hook which handles cascade)
            $order->delete();

            $deletedAt = $order->deleted_at ?: now()->toDateTimeString();

            // Ensure cascade soft delete on all related tables
            if (Schema::hasTable('pcb_order_meta') && Schema::hasColumn('pcb_order_meta', 'deleted_at')) {
                DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $orderId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (Schema::hasTable('pcb_order_status_histories') && Schema::hasColumn('pcb_order_status_histories', 'deleted_at')) {
                DB::table('pcb_order_status_histories')
                    ->where('pcb_order_id', $orderId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (Schema::hasTable('pcb_order_notes') && Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                DB::table('pcb_order_notes')
                    ->where('pcb_order_id', $orderId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (Schema::hasTable('job_card_documents') && Schema::hasColumn('job_card_documents', 'deleted_at')) {
                DB::table('job_card_documents')
                    ->where('pcb_order_id', $orderId)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (Schema::hasTable('pcb_order_combos') && Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                DB::table('pcb_order_combos')
                    ->where(function ($q) use ($orderId) {
                        $q->where('parent_order_id', $orderId)
                          ->orWhere('combo_order_id', $orderId);
                    })
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (Schema::hasTable('pcb_order_old_orders') && Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                DB::table('pcb_order_old_orders')
                    ->where(function ($q) use ($orderId) {
                        $q->where('order_id', $orderId)
                          ->orWhere('old_order_id', $orderId);
                    })
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            if (!empty($order->transaction_id) && Schema::hasTable('payment_transactions') && Schema::hasColumn('payment_transactions', 'deleted_at')) {
                $hasOtherActiveOrder = DB::table('pcb_orders')
                    ->where('transaction_id', $order->transaction_id)
                    ->where('id', '!=', $orderId)
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$hasOtherActiveOrder) {
                    DB::table('payment_transactions')
                        ->where('id', $order->transaction_id)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }
            }

            if (Schema::hasTable('pcb_order_logs')) {
                $adminName = $request->attributes->get('admin_name') ?: ($admin->name ?? "Admin #{$adminId}");
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'admin_id'     => $adminId,
                    'action'       => 'Order Soft Deleted',
                    'description'  => "Order #{$orderNumber} and associated metadata, payments, status histories, notes, and documents were soft deleted via mobile app by {$adminName}",
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Order #{$orderNumber} deleted successfully."
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}

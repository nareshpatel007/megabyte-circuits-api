<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
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
                    'Completed',
                    'Cancelled',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => array_values(array_unique($statuses))
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

            if ($statusTable) {
                $query->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                      ->select(
                          'pcb_orders.*',
                          "{$statusTable}.name as dynamic_status_name",
                          "{$statusTable}.slug as dynamic_status_slug"
                      );
            } else {
                $query->select('pcb_orders.*');
            }

            // Hide combo member child orders from mobile production list by default (show parent & normal orders only)
            // But when user is explicitly searching ($search !== ''), include child combo orders so the searched order is found!
            if ($search === '' && Schema::hasTable('pcb_order_combos')) {
                $query->whereNotIn('pcb_orders.id', function ($subQuery) {
                    $subQuery->select('combo_order_id')->from('pcb_order_combos');
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

            // Status filtering exactly matching Admin Panel (OrderController)
            $statusLower = strtolower(trim($status));
            $isSearchActive = ($search !== '');

            if ($statusLower === 'all' || $statusLower === 'all_statuses' || ($isSearchActive && ($statusLower === '' || $statusLower === 'in_production' || $statusLower === 'in production'))) {
                // When explicitly requested 'all' or when searching with a query, search across all statuses
            } elseif ($statusLower === '' || $statusLower === 'in_production' || $statusLower === 'in production') {
                // Admin Panel definition of "In Production": exclude pending, completed, shipped, delivered, cancelled, etc.
                $excluded = ['pending', 'completed', 'shipped', 'delivered', 'cancelled', 'canceled', 'archived'];

                if ($statusTable) {
                    $query->where(function ($q) use ($excluded, $statusTable) {
                        $q->where(function ($sq) use ($excluded) {
                            $sq->whereNull('pcb_orders.status_id')
                               ->whereNotIn(DB::raw('LOWER(TRIM(pcb_orders.status))'), $excluded);
                        })->orWhere(function ($sq) use ($excluded, $statusTable) {
                            $sq->whereNotNull('pcb_orders.status_id')
                               ->whereNotIn(DB::raw("LOWER(TRIM({$statusTable}.name))"), $excluded)
                               ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE({$statusTable}.slug, '')))"), $excluded);
                        });
                    });
                } else {
                    $query->whereNotIn(DB::raw('LOWER(TRIM(pcb_orders.status))'), $excluded);
                }
            } else {
                // Specific status requested (e.g. "Pending", "Traveler", "Drilling", etc.)
                if ($statusTable) {
                    $query->where(function ($q) use ($statusLower, $status, $statusTable) {
                        $q->where(function ($sq) use ($statusLower, $status) {
                            $sq->whereNull('pcb_orders.status_id')
                               ->where(function ($ssq) use ($statusLower, $status) {
                                   $ssq->where(DB::raw('LOWER(TRIM(pcb_orders.status))'), $statusLower)
                                       ->orWhere('pcb_orders.status', 'LIKE', "%{$status}%");
                               });
                        })->orWhere(function ($sq) use ($statusLower, $status, $statusTable) {
                            $sq->whereNotNull('pcb_orders.status_id')
                               ->where(function ($ssq) use ($statusLower, $status, $statusTable) {
                                   $ssq->where(DB::raw("LOWER(TRIM({$statusTable}.name))"), $statusLower)
                                       ->orWhere("{$statusTable}.name", 'LIKE', "%{$status}%")
                                       ->orWhere(DB::raw("LOWER(TRIM(COALESCE({$statusTable}.slug, '')))"), $statusLower);
                               });
                        });
                    });
                } else {
                    $query->where(function ($q) use ($statusLower, $status) {
                        $q->where(DB::raw('LOWER(TRIM(pcb_orders.status))'), $statusLower)
                           ->orWhere('pcb_orders.status', 'LIKE', "%{$status}%");
                    });
                }
            }

            $total = $query->count();

            $sortBy = trim((string)$request->input('sort_by', 'delivery_date'));
            $sortOrder = strtolower(trim((string)$request->input('sort_order', 'asc'))) === 'desc' ? 'desc' : 'asc';

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

            // Order by delivery date (earliest due date first, NULLs last)
            if ($sortBy === 'delivery_date') {
                $query->orderByRaw("CASE WHEN pcb_orders.delivery_date IS NULL THEN 1 ELSE 0 END ASC")
                      ->orderBy('pcb_orders.delivery_date', $sortOrder)
                      ->orderBy('pcb_orders.created_at', 'desc')
                      ->orderBy('pcb_orders.id', 'desc');
            } elseif ($sortBy === 'created_at' || $sortBy === 'order_date') {
                $query->orderBy('pcb_orders.created_at', $sortOrder)
                      ->orderBy('pcb_orders.id', $sortOrder);
            } else {
                $query->orderBy('pcb_orders.' . $sortBy, $sortOrder)
                      ->orderBy('pcb_orders.id', 'desc');
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
                    ->select('pcb_order_combos.combo_order_id', 'pcb_orders.order_number')
                    ->get()
                    ->groupBy('combo_order_id')
                    ->map(function ($rows) {
                        return $rows->pluck('order_number')->filter()->implode(', ');
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

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'order_number' => $order->order_number ?? ('M' . $order->id),
                    'status' => ucfirst($displayStatus),
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'pn_number' => $order->pn_number ?? $metaMap['pn_number'] ?? $metaMap['p_n'] ?? $metaMap['part_number'] ?? null,
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

            $data = [
                'id' => (string) $order->id,
                'tool' => $order->order_number ?? ('M' . $order->id),
                'status' => ucfirst($displayStatus),
                'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                'pn_number' => $order->pn_number ?? $metaMap['pn_number'] ?? $metaMap['p_n'] ?? $metaMap['part_number'] ?? null,
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
                'launchQty' => $launchQty,
                'finalQty' => $finalQty,
                'failedQty' => $failedQty,
                'pendingQty' => $pendingQty,
                'combo' => $order->combo ?? null,
                'combo_orders' => Schema::hasTable('pcb_order_combos') ? DB::table('pcb_order_combos')->join('pcb_orders', 'pcb_order_combos.combo_order_id', '=', 'pcb_orders.id')->where('pcb_order_combos.parent_order_id', $order->id)->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')->get() : [],
                'combo_parent' => Schema::hasTable('pcb_order_combos') ? DB::table('pcb_order_combos')->join('pcb_orders', 'pcb_order_combos.parent_order_id', '=', 'pcb_orders.id')->where('pcb_order_combos.combo_order_id', $order->id)->pluck('pcb_orders.order_number')->filter()->implode(', ') : null,
                'parent_combo_orders' => Schema::hasTable('pcb_order_combos') ? DB::table('pcb_order_combos')->join('pcb_orders', 'pcb_order_combos.parent_order_id', '=', 'pcb_orders.id')->where('pcb_order_combos.combo_order_id', $order->id)->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')->get() : [],
                'lastUpdate' => ($order->updated_at ?? null) ? date('h:i A', strtotime($order->updated_at)) : 'Just now',
                'user_email' => $order->user_email ?? '',
                'user_mobile' => $order->user_mobile ?? '',
                'unit_price' => (float)($order->unit_price ?? 0),
                'order_value' => (float)($order->order_value ?? 0),
                'history' => $history,
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
            $notes = [];
            if (Schema::hasTable('pcb_order_notes')) {
                $notes = DB::table('pcb_order_notes')
                    ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                    ->where('pcb_order_notes.pcb_order_id', $id)
                    ->orderBy('pcb_order_notes.id', 'desc')
                    ->select(
                        'pcb_order_notes.id',
                        'pcb_order_notes.note',
                        'pcb_order_notes.created_at',
                        'admins.name as name',
                        'admins.name as admin_name'
                    )
                    ->get()
                    ->map(function ($n) {
                        return [
                            'id' => (string) $n->id,
                            'note' => $n->note,
                            'name' => $n->name ?: 'System / Staff',
                            'admin_name' => $n->admin_name ?: 'System / Staff',
                            'created_at' => ($n->created_at ?? null) ? date('d M Y, h:i A', strtotime($n->created_at)) : ''
                        ];
                    });
            }

            return response()->json([
                'success' => true,
                'data' => $notes
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

            $adminId = $request->attributes->get('admin_id') ?: 1;

            if (Schema::hasTable('pcb_order_notes')) {
                DB::table('pcb_order_notes')->insert([
                    'pcb_order_id' => $id,
                    'admin_id' => $adminId,
                    'note' => $note,
                    'is_internal' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
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
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit orders.'], 403);
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            $updateData = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('customer_name')) $updateData['customer_name'] = trim($request->input('customer_name'));
            if ($request->has('board_name')) $updateData['board_name'] = trim($request->input('board_name'));
            if ($request->has('pn_number')) $updateData['pn_number'] = trim($request->input('pn_number')) ?: null;
            if ($request->has('quantity')) {
                $qty = (int)$request->input('quantity');
                $updateData['order_qty'] = $qty;
                $updateData['launch_qty'] = $qty;
            }
            if ($request->has('status')) $updateData['status'] = trim($request->input('status'));

            DB::table('pcb_orders')->where('id', $id)->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully'
            ]);
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
            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            DB::table('pcb_orders')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

            return response()->json([
                'success' => true,
                'message' => 'Order deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}

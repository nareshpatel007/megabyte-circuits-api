<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class MobileDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            $admin = null;
            if ($adminId) {
                $admin = DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first();
            }

            $name = $admin->name ?? 'Jignesh Dave';
            $firstName = explode(' ', trim($name))[0];

            $hour = (int) date('G');
            if ($hour < 12) {
                $greetingPrefix = 'Good morning';
            } elseif ($hour < 17) {
                $greetingPrefix = 'Good afternoon';
            } else {
                $greetingPrefix = 'Good evening';
            }

            $dateFormatted = strtoupper(date('l · d M Y'));

            // Summary Counts from pcb_orders matching Web Admin logic
            $today = date('Y-m-d');
            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : (Schema::hasTable('statuses') ? 'statuses' : null));

            $totalJobs = DB::table('pcb_orders')->whereNull('deleted_at')->count();

            // Manufacturing runs in progress (exclude completed, delivered, and cancelled)
            $completedStatuses = ['completed', 'shipped', 'delivered', 'cancelled', 'canceled'];
            $inProgress = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), $completedStatuses)
                ->count();

            // Ready to ship count
            $readyToShip = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($statusTable) {
                    $q->where(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), 'ready to ship');
                    if ($statusTable) {
                        $q->orWhereExists(function ($sub) use ($statusTable) {
                            $sub->select(DB::raw(1))
                                ->from($statusTable)
                                ->whereColumn("{$statusTable}.id", 'pcb_orders.status_id')
                                ->where(DB::raw("LOWER(TRIM({$statusTable}.name))"), 'ready to ship');
                        });
                    }
                })->count();

            // Overdue orders in production
            $overdue = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), array_merge($completedStatuses, ['ready to ship']))
                ->whereNotNull('delivery_date')
                ->whereDate('delivery_date', '<', $today)
                ->count();

            // Due today in production
            $dueToday = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(status, '')))"), $completedStatuses)
                ->whereNotNull('delivery_date')
                ->whereDate('delivery_date', '=', $today)
                ->count();

            // Today's Production (Top 5 orders currently in production / active stages)
            $ordersQuery = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->whereNotIn(DB::raw('LOWER(COALESCE(status, ""))'), $completedStatuses);

            if ($statusTable) {
                $ordersQuery->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                    ->select('pcb_orders.*', "{$statusTable}.name as dynamic_status_name");
            } else {
                $ordersQuery->select('pcb_orders.*');
            }

            $orders = $ordersQuery->orderBy('pcb_orders.id', 'desc')->limit(5)->get();

            $todayProduction = $orders->map(function ($order) use ($today) {
                $dueDateStr = 'Upcoming';
                if ($order->delivery_date) {
                    if ($order->delivery_date === $today) {
                        $dueDateStr = 'Today';
                    } else {
                        $dueDateStr = date('d M Y', strtotime($order->delivery_date));
                    }
                }

                $metaMap = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key')
                    ->toArray();

                $orderQty = (int) ($order->order_qty ?? $order->quantity ?? $metaMap['quantity'] ?? $metaMap['order_qty'] ?? 50);
                $launchQty = (int) ($order->launch_qty ?? $metaMap['launch_qty'] ?? $orderQty);
                $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
                $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
                $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

                $resolvedStatus = $order->status;
                if (isset($order->dynamic_status_name) && !empty($order->dynamic_status_name)) {
                    $resolvedStatus = $order->dynamic_status_name;
                }

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'status' => $resolvedStatus ?: 'Traveler',
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                    'department' => $metaMap['department'] ?? 'Production',
                    'priority' => $metaMap['priority'] ?? 'Normal',
                    'orderDate' => $order->created_at ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                    'dueDate' => $dueDateStr,
                    'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                    'maskColor' => $this->resolveMaskColor($order, $metaMap),
                    'layers' => $this->resolveLayers($order, $metaMap),
                    'quantity' => $orderQty,
                    'launchQty' => $launchQty,
                    'finalQty' => $finalQty,
                    'failedQty' => $failedQty,
                    'pendingQty' => $pendingQty,
                    'lastUpdate' => $order->updated_at ? date('h:i A', strtotime($order->updated_at)) : 'Just now'
                ];
            });

            // Status normalization helper for robust, case-insensitive cross-department matching
            $normalizeKey = function ($str) {
                $s = strtolower(trim((string)$str));
                $s = str_replace(['/', '-', '_', ' '], '', $s);
                if ($s === 'devloping') $s = 'developing';
                if ($s === 'canceled') $s = 'cancelled';
                return $s;
            };

            // Department load calculation from status counts with master status table join
            $joinClause = $statusTable ? "COALESCE(NULLIF(TRIM(pcb_orders.status), ''), {$statusTable}.name, 'Pending')" : "COALESCE(NULLIF(TRIM(pcb_orders.status), ''), 'Pending')";
            $query = DB::table('pcb_orders');
            if ($statusTable) {
                $query->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id");
            }
            $rawCounts = $query->select(DB::raw("{$joinClause} as status_name"), DB::raw('count(*) as total'))
                ->whereNull('pcb_orders.deleted_at')
                ->groupBy('status_name')
                ->get();

            // Map counts by normalized key
            $countsByKey = [];
            foreach ($rawCounts as $row) {
                $k = $normalizeKey($row->status_name);
                $countsByKey[$k] = ($countsByKey[$k] ?? 0) + intval($row->total);
            }

            // Also check direct pcb_orders.status values if any orders had orphaned status_id
            $directCounts = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->whereNotNull('status')
                ->where('status', '!=', '')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            foreach ($directCounts as $dc) {
                $k = $normalizeKey($dc->status);
                // If this status wasn't covered in rawCounts, add it
                if (!isset($countsByKey[$k])) {
                    $countsByKey[$k] = intval($dc->total);
                }
            }

            // Standard production status list matching Mobile and Web Admin
            $defaultStatuses = [
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
                'Cancelled'
            ];

            // If master status table has additional active statuses, append them
            if ($statusTable) {
                $dbMasterStatuses = DB::table($statusTable)->where('is_active', 1)->orderBy('sort_order', 'asc')->pluck('name')->toArray();
                foreach ($dbMasterStatuses as $stName) {
                    $stNameClean = trim($stName);
                    $k = $normalizeKey($stNameClean);
                    $exists = false;
                    foreach ($defaultStatuses as $ds) {
                        if ($normalizeKey($ds) === $k) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists && $stNameClean !== '') {
                        $defaultStatuses[] = $stNameClean;
                    }
                }
            }

            // Build full department load array with exact counts for ALL statuses (NO truncation)
            $departmentLoad = collect($defaultStatuses)->map(function ($name) use ($countsByKey, $normalizeKey) {
                $k = $normalizeKey($name);
                return [
                    'name' => $name,
                    'status' => $name,
                    'count' => $countsByKey[$k] ?? 0
                ];
            })->values();

            // Mask colors breakdown
            $maskCountsMap = DB::table('pcb_order_meta')
                ->where('meta_key', 'mask_color')
                ->select('meta_value as mask', DB::raw('count(*) as count'))
                ->groupBy('meta_value')
                ->pluck('count', 'mask')
                ->toArray();

            $maskColors = [
                ['name' => 'Green', 'count' => (int)($maskCountsMap['Green'] ?? 0), 'color' => '#2fa34a'],
                ['name' => 'White', 'count' => (int)($maskCountsMap['White'] ?? 0), 'color' => '#d8d8d8'],
                ['name' => 'Black', 'count' => (int)($maskCountsMap['Black'] ?? 0), 'color' => '#1c2420'],
                ['name' => 'Red', 'count' => (int)($maskCountsMap['Red'] ?? 0), 'color' => '#dc5a52'],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $dateFormatted,
                    'greeting' => "{$greetingPrefix}, {$firstName}",
                    'subheading' => "Here's today's production overview.",
                    'user' => [
                        'name' => $name,
                        'first_name' => $firstName
                    ],
                    'company' => [
                        'name' => "Megabyte's Circuit Systems",
                        'logo' => null
                    ],
                    'summary' => [
                        'total_jobs' => $totalJobs,
                        'ready_to_ship' => $readyToShip,
                        'in_progress' => $inProgress,
                        'overdue' => $overdue,
                        'due_today' => $dueToday
                    ],
                    'today_production' => $todayProduction,
                    'department_load' => $departmentLoad,
                    'mask_colors' => $maskColors
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
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
}

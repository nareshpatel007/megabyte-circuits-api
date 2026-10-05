<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Models\PcbOrder;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Jobs\SendTemplateEmailJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class DailyReportService
{
    /**
     * Get start and end datetime bounds for target report date in app timezone.
     */
    public static function getDateBounds(?string $targetDateStr = null): array
    {
        $timezone = config('app.timezone', 'Asia/Kolkata');
        
        if (!empty($targetDateStr)) {
            $date = Carbon::parse($targetDateStr, $timezone);
        } else {
            $date = Carbon::now($timezone);
        }

        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        return [
            'date_str' => $start->format('d F Y'),
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Build variables & rendered tables for Daily Order Progress Report.
     */
    public static function buildOrderReportVariables(?string $targetDateStr = null): array
    {
        $bounds = self::getDateBounds($targetDateStr);
        $dateStr = $bounds['date_str'];
        $start = $bounds['start'];
        $end = $bounds['end'];

        if (!Schema::hasTable('pcb_orders')) {
            return self::getSampleOrderReportVariables($dateStr);
        }

        // Query orders created today
        $newOrders = PcbOrder::with('user')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // Query status histories / movements today
        $statusMovements = [];
        if (Schema::hasTable('pcb_order_status_histories')) {
            $selectCols = [
                'pcb_order_status_histories.*',
                'pcb_orders.order_number',
                'admins.name as admin_name',
            ];
            if (Schema::hasColumn('pcb_orders', 'customer_name')) {
                $selectCols[] = 'pcb_orders.customer_name';
            }
            if (Schema::hasColumn('pcb_orders', 'user_email')) {
                $selectCols[] = 'pcb_orders.user_email';
            }

            $statusMovements = DB::table('pcb_order_status_histories')
                ->join('pcb_orders', 'pcb_order_status_histories.pcb_order_id', '=', 'pcb_orders.id')
                ->leftJoin('admins', 'pcb_order_status_histories.admin_id', '=', 'admins.id')
                ->whereBetween('pcb_order_status_histories.created_at', [$start, $end])
                ->select($selectCols)
                ->orderBy('pcb_order_status_histories.id', 'desc')
                ->get();
        }

        // Active summary counts
        $totalOrdersCount = PcbOrder::whereNull('deleted_at')->count();
        $newOrdersCount = $newOrders->count();
        
        // Completed orders today
        $completedOrders = PcbOrder::with('user')
            ->whereIn(DB::raw('LOWER(status)'), ['completed', 'delivered', 'order completed'])
            ->whereBetween('updated_at', [$start, $end])
            ->get();
        $completedCount = $completedOrders->count();

        // Cancelled orders today
        $cancelledOrders = PcbOrder::with('user')
            ->whereIn(DB::raw('LOWER(status)'), ['cancelled', 'canceled', 'rejected'])
            ->whereBetween('updated_at', [$start, $end])
            ->get();
        $cancelledCount = $cancelledOrders->count();

        // Production orders currently active
        $productionOrders = PcbOrder::with('user')
            ->whereIn(DB::raw('LOWER(status)'), ['in production', 'processing', 'manufacturing', 'production', 'traveler'])
            ->orderBy('id', 'desc')
            ->get();
        $productionCount = $productionOrders->count();

        // Pending orders currently active
        $pendingOrders = PcbOrder::with('user')
            ->where(function($q) {
                $q->whereNull('status')
                  ->orWhere(DB::raw('LOWER(status)'), 'pending')
                  ->orWhere(DB::raw('LOWER(status)'), 'move');
            })
            ->orderBy('id', 'desc')
            ->get();
        $pendingCount = $pendingOrders->count();

        // Film not applied alerts
        $filmNotAppliedOrders = PcbOrder::with('user')
            ->where('film_applied', '!=', 1)
            ->whereIn(DB::raw('LOWER(status)'), ['in production', 'processing', 'manufacturing', 'production', 'traveler'])
            ->get();

        // Total order value & average order value
        $totalValue = $newOrders->sum(function ($ord) {
            return (float)($ord->order_value ?? 0);
        });
        $avgValue = $newOrdersCount > 0 ? ($totalValue / $newOrdersCount) : 0;

        $cartBaseUrl = config('app.cart_url', 'https://cart.megabytecircuit.com');

        // Render HTML Tables
        $newOrdersTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Date / Time', 'Status', 'Total', 'Delivery Date'],
            'rows' => $newOrders->map(function ($o) use ($cartBaseUrl) {
                $cName = $o->customer_name ?: ($o->user->name ?? strtok($o->user_email ?? '', '@') ?: 'Valued Customer');
                $dDate = $o->delivery_date ? Carbon::parse($o->delivery_date)->format('d M Y') : 'N/A';
                return [
                    "<a href='" . rtrim($cartBaseUrl, '/') . "/orders' style='color:#10b981;font-weight:bold;text-decoration:none;'>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</a>",
                    htmlspecialchars($cName),
                    $o->created_at ? $o->created_at->format('d M Y, h:i A') : 'N/A',
                    "<span style='display:inline-block;padding:2px 8px;background:#ecfdf5;color:#047857;border-radius:4px;font-weight:bold;font-size:12px;'>" . htmlspecialchars(ucfirst($o->status ?? 'Pending')) . "</span>",
                    '₹' . number_format($o->order_value ?? 0, 2),
                    $dDate,
                ];
            })->toArray(),
            'empty_msg' => 'No new orders were created today.'
        ]);

        $movementsTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Status Name / Remark', 'Changed At'],
            'rows' => collect($statusMovements)->map(function (object $m) {
                $m = (object)$m;
                $cName = $m->customer_name ?: (strtok($m->user_email ?? '', '@') ?: 'Valued Customer');
                return [
                    "<strong>" . htmlspecialchars($m->order_number ?? "ORD-{$m->pcb_order_id}") . "</strong>",
                    htmlspecialchars($cName),
                    "Status: <strong>" . htmlspecialchars(ucfirst($m->status_name ?? 'Updated')) . "</strong>" . ($m->remark ? "<br/><small style='color:#6b7280;'>" . htmlspecialchars($m->remark) . "</small>" : ""),
                    Carbon::parse($m->created_at)->format('h:i A'),
                ];
            })->toArray(),
            'empty_msg' => 'No order status movements were recorded today.'
        ]);

        $completedTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Completion Time', 'Total', 'Delivery Date'],
            'rows' => $completedOrders->map(function ($o) {
                $cName = $o->customer_name ?: ($o->user->name ?? 'Customer');
                return [
                    "<strong>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</strong>",
                    htmlspecialchars($cName),
                    $o->updated_at ? $o->updated_at->format('h:i A') : 'N/A',
                    '₹' . number_format($o->order_value ?? 0, 2),
                    $o->delivery_date ? Carbon::parse($o->delivery_date)->format('d M Y') : 'N/A',
                ];
            })->toArray(),
            'empty_msg' => 'No orders were completed today.'
        ]);

        $cancelledTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Cancellation Time', 'Total'],
            'rows' => $cancelledOrders->map(function ($o) {
                $cName = $o->customer_name ?: ($o->user->name ?? 'Customer');
                return [
                    "<strong>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</strong>",
                    htmlspecialchars($cName),
                    $o->updated_at ? $o->updated_at->format('h:i A') : 'N/A',
                    '₹' . number_format($o->order_value ?? 0, 2),
                ];
            })->toArray(),
            'empty_msg' => 'No orders were cancelled today.'
        ]);

        $productionTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Status', 'Order Date', 'Delivery Date'],
            'rows' => $productionOrders->map(function ($o) {
                $cName = $o->customer_name ?: ($o->user->name ?? 'Customer');
                return [
                    "<strong>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</strong>",
                    htmlspecialchars($cName),
                    "<span style='color:#d97706;font-weight:bold;'>" . htmlspecialchars(ucfirst($o->status)) . "</span>",
                    $o->created_at ? $o->created_at->format('d M Y') : 'N/A',
                    $o->delivery_date ? Carbon::parse($o->delivery_date)->format('d M Y') : 'N/A',
                ];
            })->toArray(),
            'empty_msg' => 'No orders currently in production.'
        ]);

        $pendingTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Created Date', 'Order Age', 'Total'],
            'rows' => $pendingOrders->map(function ($o) {
                $cName = $o->customer_name ?: ($o->user->name ?? 'Customer');
                $ageDays = $o->created_at ? $o->created_at->diffInDays(Carbon::now()) : 0;
                $ageStr = $ageDays == 0 ? 'Today' : "{$ageDays} day" . ($ageDays > 1 ? 's' : '');
                return [
                    "<strong>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</strong>",
                    htmlspecialchars($cName),
                    $o->created_at ? $o->created_at->format('d M Y') : 'N/A',
                    "<span style='color:#dc2626;font-weight:bold;'>{$ageStr}</span>",
                    '₹' . number_format($o->order_value ?? 0, 2),
                ];
            })->toArray(),
            'empty_msg' => 'No pending orders.'
        ]);

        $filmNotAppliedTable = self::renderTable([
            'headers' => ['Order #', 'Customer', 'Current Status', 'Film Applied'],
            'rows' => $filmNotAppliedOrders->map(function ($o) {
                $cName = $o->customer_name ?: ($o->user->name ?? 'Customer');
                return [
                    "<strong>" . htmlspecialchars($o->order_number ?? "M{$o->id}") . "</strong>",
                    htmlspecialchars($cName),
                    htmlspecialchars(ucfirst($o->status)),
                    "<span style='color:#dc2626;font-weight:bold;'>No (Film Not Applied)</span>",
                ];
            })->toArray(),
            'empty_msg' => 'No orders were identified with this issue today.'
        ]);

        return [
            'report_date'             => $dateStr,
            'report_start'            => $start->format('d M Y, h:i A'),
            'report_end'              => $end->format('d M Y, h:i A'),
            'total_orders'            => (string)$totalOrdersCount,
            'new_orders_count'        => (string)$newOrdersCount,
            'completed_orders_count'  => (string)$completedCount,
            'cancelled_orders_count'  => (string)$cancelledCount,
            'pending_orders_count'    => (string)$pendingCount,
            'production_orders_count' => (string)$productionCount,
            'total_order_value'       => '₹' . number_format($totalValue, 2),
            'average_order_value'     => '₹' . number_format($avgValue, 2),
            'new_orders_table'        => $newOrdersTable,
            'status_movements_table'  => $movementsTable,
            'completed_orders_table'  => $completedTable,
            'cancelled_orders_table'  => $cancelledTable,
            'production_orders_table' => $productionTable,
            'pending_orders_table'    => $pendingTable,
            'film_not_applied_table'  => $filmNotAppliedTable,
        ];
    }

    /**
     * Build variables & rendered tables for Daily Inventory & Stock Movement Report.
     */
    public static function buildInventoryReportVariables(?string $targetDateStr = null): array
    {
        $bounds = self::getDateBounds($targetDateStr);
        $dateStr = $bounds['date_str'];
        $start = $bounds['start'];
        $end = $bounds['end'];

        if (!Schema::hasTable('inventory_items')) {
            return self::getSampleInventoryReportVariables($dateStr);
        }

        $items = InventoryItem::whereNull('deleted_at')->get();
        $totalItems = $items->count();

        $itemsWithStock = $items->filter(fn($i) => $i->available_quantity > 0)->count();
        $lowStockItems = $items->filter(fn($i) => $i->available_quantity <= $i->low_stock_threshold && $i->available_quantity > 0);
        $outOfStockItems = $items->filter(fn($i) => $i->available_quantity <= 0);

        $logsToday = collect();
        if (Schema::hasTable('inventory_logs')) {
            $logsToday = InventoryLog::with('item')
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('id', 'desc')
                ->get();
        }

        $itemsWithMovementCount = $logsToday->pluck('inventory_item_id')->unique()->count();
        
        $stockAdded = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['in', 'add', 'added', 'stock in']))->sum('quantity');
        $stockRemoved = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['out', 'remove', 'removed', 'stock out']))->sum('quantity');
        $stockAdjustedCount = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['adjust', 'adjustment', 'set', 'adjusted']))->count();

        // Render HTML Tables
        $currentStockTable = self::renderTable([
            'headers' => ['Product Name', 'SKU', 'Available Stock', 'Min Threshold', 'Status'],
            'rows' => $items->map(function ($i) {
                $statusColor = $i->available_quantity <= 0 ? '#dc2626' : ($i->available_quantity <= $i->low_stock_threshold ? '#d97706' : '#059669');
                $statusText = $i->available_quantity <= 0 ? 'Out of Stock' : ($i->available_quantity <= $i->low_stock_threshold ? 'Low Stock' : 'In Stock');
                return [
                    "<strong>" . htmlspecialchars($i->name) . "</strong>",
                    "<code>" . htmlspecialchars($i->sku) . "</code>",
                    (string)$i->available_quantity,
                    (string)$i->low_stock_threshold,
                    "<span style='color:{$statusColor};font-weight:bold;'>{$statusText}</span>",
                ];
            })->toArray(),
            'empty_msg' => 'No inventory items found.'
        ]);

        $movementsTable = self::renderTable([
            'headers' => ['Time', 'Product', 'SKU', 'Type', 'Qty', 'Prev Qty', 'New Qty', 'Updated By'],
            'rows' => $logsToday->map(function ($l) {
                $itemName = $l->item->name ?? 'Inventory Item';
                $sku = $l->item->sku ?? 'N/A';
                $typeBadge = strtolower($l->type) === 'in' ? "<span style='color:#059669;font-weight:bold;'>Stock In</span>" : (strtolower($l->type) === 'out' ? "<span style='color:#dc2626;font-weight:bold;'>Stock Out</span>" : "<span style='color:#2563eb;font-weight:bold;'>Adjustment</span>");
                return [
                    $l->created_at ? $l->created_at->format('h:i A') : 'N/A',
                    htmlspecialchars($itemName),
                    "<code>" . htmlspecialchars($sku) . "</code>",
                    $typeBadge,
                    (string)$l->quantity,
                    (string)$l->previous_quantity,
                    (string)$l->new_quantity,
                    htmlspecialchars($l->created_by ?? 'Admin'),
                ];
            })->toArray(),
            'empty_msg' => 'No inventory movements were recorded today.'
        ]);

        $addedLogs = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['in', 'add', 'added', 'stock in']));
        $stockAddedTable = self::renderTable([
            'headers' => ['Product', 'SKU', 'Qty Added', 'Prev Stock', 'New Stock', 'Time', 'User'],
            'rows' => $addedLogs->map(function ($l) {
                return [
                    "<strong>" . htmlspecialchars($l->item->name ?? 'Item') . "</strong>",
                    "<code>" . htmlspecialchars($l->item->sku ?? 'N/A') . "</code>",
                    "<span style='color:#059669;font-weight:bold;'>+{$l->quantity}</span>",
                    (string)$l->previous_quantity,
                    (string)$l->new_quantity,
                    $l->created_at ? $l->created_at->format('h:i A') : 'N/A',
                    htmlspecialchars($l->created_by ?? 'Admin'),
                ];
            })->toArray(),
            'empty_msg' => 'No stock was added today.'
        ]);

        $removedLogs = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['out', 'remove', 'removed', 'stock out']));
        $stockRemovedTable = self::renderTable([
            'headers' => ['Product', 'SKU', 'Qty Removed', 'Prev Stock', 'New Stock', 'Time', 'User'],
            'rows' => $removedLogs->map(function ($l) {
                return [
                    "<strong>" . htmlspecialchars($l->item->name ?? 'Item') . "</strong>",
                    "<code>" . htmlspecialchars($l->item->sku ?? 'N/A') . "</code>",
                    "<span style='color:#dc2626;font-weight:bold;'>-{$l->quantity}</span>",
                    (string)$l->previous_quantity,
                    (string)$l->new_quantity,
                    $l->created_at ? $l->created_at->format('h:i A') : 'N/A',
                    htmlspecialchars($l->created_by ?? 'Admin'),
                ];
            })->toArray(),
            'empty_msg' => 'No stock was removed today.'
        ]);

        $adjLogs = $logsToday->filter(fn($l) => in_array(strtolower($l->type ?? ''), ['adjust', 'adjustment', 'set', 'adjusted']));
        $adjustmentsTable = self::renderTable([
            'headers' => ['Product', 'SKU', 'Prev Stock', 'Adjustment', 'New Stock', 'Note / Reason', 'User'],
            'rows' => $adjLogs->map(function ($l) {
                return [
                    "<strong>" . htmlspecialchars($l->item->name ?? 'Item') . "</strong>",
                    "<code>" . htmlspecialchars($l->item->sku ?? 'N/A') . "</code>",
                    (string)$l->previous_quantity,
                    (string)$l->quantity,
                    (string)$l->new_quantity,
                    htmlspecialchars($l->note ?? 'Manual adjustment'),
                    htmlspecialchars($l->created_by ?? 'Admin'),
                ];
            })->toArray(),
            'empty_msg' => 'No inventory adjustments were made today.'
        ]);

        $lowStockTable = self::renderTable([
            'headers' => ['Product', 'SKU', 'Current Stock', 'Min Threshold', 'Shortage'],
            'rows' => $lowStockItems->map(function ($i) {
                $shortage = max(0, $i->low_stock_threshold - $i->available_quantity);
                return [
                    "<strong>" . htmlspecialchars($i->name) . "</strong>",
                    "<code>" . htmlspecialchars($i->sku) . "</code>",
                    "<span style='color:#d97706;font-weight:bold;'>{$i->available_quantity}</span>",
                    (string)$i->low_stock_threshold,
                    "<span style='color:#dc2626;font-weight:bold;'>{$shortage} Pcs</span>",
                ];
            })->toArray(),
            'empty_msg' => 'No low stock items.'
        ]);

        $outOfStockTable = self::renderTable([
            'headers' => ['Product', 'SKU', 'Current Stock', 'Min Threshold'],
            'rows' => $outOfStockItems->map(function ($i) {
                return [
                    "<strong>" . htmlspecialchars($i->name) . "</strong>",
                    "<code>" . htmlspecialchars($i->sku) . "</code>",
                    "<span style='color:#dc2626;font-weight:bold;'>0 (Out of Stock)</span>",
                    (string)$i->low_stock_threshold,
                ];
            })->toArray(),
            'empty_msg' => 'No items are currently out of stock.'
        ]);

        return [
            'report_date'            => $dateStr,
            'report_start'           => $start->format('d M Y, h:i A'),
            'report_end'             => $end->format('d M Y, h:i A'),
            'total_inventory_items'  => (string)$totalItems,
            'items_with_stock'       => (string)$itemsWithStock,
            'low_stock_count'        => (string)$lowStockItems->count(),
            'out_of_stock_count'     => (string)$outOfStockItems->count(),
            'items_with_movement'    => (string)$itemsWithMovementCount,
            'total_stock_added'      => (string)$stockAdded,
            'total_stock_removed'    => (string)$stockRemoved,
            'total_stock_adjusted'   => (string)$stockAdjustedCount,
            'current_stock_table'    => $currentStockTable,
            'stock_movements_table'  => $movementsTable,
            'stock_added_table'      => $stockAddedTable,
            'stock_removed_table'    => $stockRemovedTable,
            'adjustments_table'      => $adjustmentsTable,
            'low_stock_table'        => $lowStockTable,
            'out_of_stock_table'     => $outOfStockTable,
        ];
    }

    /**
     * Send Daily Order Progress Report email.
     */
    public static function sendDailyOrderReport(?string $targetDateStr = null, bool $force = false): array
    {
        $bounds = self::getDateBounds($targetDateStr);
        $reportDateStr = $bounds['start']->format('Y-m-d');

        // Check template exists and active
        if (!EmailTemplateService::isTemplateActive('daily_order_progress_report')) {
            \Illuminate\Support\Facades\Log::info("Email skipped: template 'daily_order_progress_report' is inactive.");
            return [
                'success' => false,
                'message' => "Email skipped: template 'daily_order_progress_report' is inactive."
            ];
        }

        // Idempotency check: don't duplicate daily report on same date unless forced
        if (!$force) {
            $alreadySent = EmailLog::where('template_key', 'daily_order_progress_report')
                ->where('status', 'sent')
                ->whereDate('created_at', $reportDateStr)
                ->exists();

            if ($alreadySent) {
                return [
                    'success' => true,
                    'message' => "Daily Order Progress Report for {$bounds['date_str']} has already been sent today. Skipping duplicate send."
                ];
            }
        }

        $reportVars = self::buildOrderReportVariables($targetDateStr);

        // Render & dispatch via SendTemplateEmailJob
        // We pass orderId = 0 as synthetic ID for report templates
        $rendered = EmailTemplateService::render('daily_order_progress_report', null, $reportVars);

        if (!$rendered['success']) {
            return $rendered;
        }

        return self::executeReportSend('daily_order_progress_report', $rendered, $bounds['date_str']);
    }

    /**
     * Send Daily Inventory & Stock Movement Report email.
     */
    public static function sendDailyInventoryReport(?string $targetDateStr = null, bool $force = false): array
    {
        $bounds = self::getDateBounds($targetDateStr);
        $reportDateStr = $bounds['start']->format('Y-m-d');

        if (!EmailTemplateService::isTemplateActive('daily_inventory_report')) {
            \Illuminate\Support\Facades\Log::info("Email skipped: template 'daily_inventory_report' is inactive.");
            return [
                'success' => false,
                'message' => "Email skipped: template 'daily_inventory_report' is inactive."
            ];
        }

        if (!$force) {
            $alreadySent = EmailLog::where('template_key', 'daily_inventory_report')
                ->where('status', 'sent')
                ->whereDate('created_at', $reportDateStr)
                ->exists();

            if ($alreadySent) {
                return [
                    'success' => true,
                    'message' => "Daily Inventory Report for {$bounds['date_str']} has already been sent today. Skipping duplicate send."
                ];
            }
        }

        $reportVars = self::buildInventoryReportVariables($targetDateStr);
        $rendered = EmailTemplateService::renderInventory('daily_inventory_report', null, null, $reportVars);

        if (!$rendered['success']) {
            return $rendered;
        }

        return self::executeReportSend('daily_inventory_report', $rendered, $bounds['date_str']);
    }

    /**
     * Execute mail sending for rendered report payload.
     */
    private static function executeReportSend(string $templateKey, array $rendered, string $dateStr): array
    {
        $toEmail   = $rendered['to'];
        $fromEmail = $rendered['from_email'];
        $fromName  = $rendered['from_name'];

        // Configure global mailer
        $mailer = 'smtp_global';
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
        \Illuminate\Support\Facades\Config::set('mail.from.address', $fromEmail);
        \Illuminate\Support\Facades\Config::set('mail.from.name', $fromName);

        try {
            \Illuminate\Support\Facades\Mail::mailer($mailer)->send([], [], function ($message) use ($toEmail, $fromEmail, $fromName, $rendered) {
                $message->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($rendered['subject'])
                    ->html($rendered['body']);

                if (!empty($rendered['cc'])) {
                    $message->cc($rendered['cc']);
                }
                if (!empty($rendered['bcc'])) {
                    $message->bcc($rendered['bcc']);
                }
            });

            EmailLog::create([
                'template_key'  => $templateKey,
                'order_id'      => null,
                'customer_id'   => null,
                'from_email'    => $fromEmail,
                'from_name'     => $fromName,
                'to'            => $toEmail,
                'cc'            => implode(', ', $rendered['cc'] ?? []),
                'bcc'           => implode(', ', $rendered['bcc'] ?? []),
                'subject'       => $rendered['subject'],
                'status'        => 'sent',
                'sent_at'       => Carbon::now(),
            ]);

            Log::info("DailyReportService: Report '{$templateKey}' sent to '{$toEmail}' for date {$dateStr}.");

            return [
                'success' => true,
                'message' => "Report '{$templateKey}' sent successfully to {$toEmail} for {$dateStr}."
            ];
        } catch (\Throwable $th) {
            EmailLog::create([
                'template_key'  => $templateKey,
                'order_id'      => null,
                'customer_id'   => null,
                'from_email'    => $fromEmail,
                'from_name'     => $fromName,
                'to'            => $toEmail,
                'cc'            => implode(', ', $rendered['cc'] ?? []),
                'bcc'           => implode(', ', $rendered['bcc'] ?? []),
                'subject'       => $rendered['subject'] ?? 'Daily Report',
                'status'        => 'failed',
                'error_message' => $th->getMessage(),
            ]);

            Log::error("DailyReportService: Failed to send report '{$templateKey}': " . $th->getMessage());

            return [
                'success' => false,
                'message' => "Failed to send report '{$templateKey}': " . $th->getMessage()
            ];
        }
    }

    /**
     * Helper to render clean HTML data tables.
     */
    public static function renderTable(array $data): string
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];
        $emptyMsg = $data['empty_msg'] ?? 'No records found.';

        if (empty($rows)) {
            return '<div style="padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-style:italic;font-size:13px;margin-bottom:18px;">
                ' . htmlspecialchars($emptyMsg) . '
            </div>';
        }

        $html = '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;border:1px solid #e5e7eb;">';
        
        // Header
        $html .= '<tr style="background:#f3f4f6;border-bottom:2px solid #e5e7eb;">';
        foreach ($headers as $h) {
            $html .= '<th style="padding:8px 10px;text-align:left;font-weight:bold;color:#374151;">' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr>';

        // Rows
        foreach ($rows as $rIdx => $r) {
            $bg = ($rIdx % 2 === 0) ? '#ffffff' : '#f9fafb';
            $html .= '<tr style="background:' . $bg . ';border-bottom:1px solid #e5e7eb;">';
            foreach ($r as $cVal) {
                $html .= '<td style="padding:8px 10px;color:#1f2937;">' . $cVal . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Dummy sample variables for preview / test emails.
     */
    public static function getSampleOrderReportVariables(string $dateStr): array
    {
        $sampleNewOrders = [
            ["<a href='#' style='color:#10b981;font-weight:bold;text-decoration:none;'>ORD-10001</a>", "John Doe", "09:30 AM", "<span style='color:#059669;font-weight:bold;'>In Production</span>", "₹5,000.00", "28 Sep 2026"],
            ["<a href='#' style='color:#10b981;font-weight:bold;text-decoration:none;'>ORD-10002</a>", "ABC Electronics", "11:15 AM", "<span style='color:#d97706;font-weight:bold;'>Pending</span>", "₹12,500.00", "30 Sep 2026"],
        ];

        $sampleMovements = [
            ["<strong>ORD-10001</strong>", "John Doe", "Status: <strong>In Production</strong><br/><small style='color:#6b7280;'>Moved to production line 1</small>", "09:30 AM"],
            ["<strong>ORD-10003</strong>", "XYZ Corp", "Status: <strong>Completed</strong>", "02:15 PM"],
        ];

        return [
            'report_date'             => $dateStr,
            'report_start'            => $dateStr . ', 12:00 AM',
            'report_end'              => $dateStr . ', 11:59 PM',
            'total_orders'            => '25',
            'new_orders_count'        => '8',
            'completed_orders_count'  => '5',
            'cancelled_orders_count'  => '1',
            'pending_orders_count'    => '4',
            'production_orders_count' => '7',
            'total_order_value'       => '₹125,500.00',
            'average_order_value'     => '₹15,687.50',
            'new_orders_table'        => self::renderTable(['headers' => ['Order #', 'Customer', 'Date / Time', 'Status', 'Total', 'Delivery Date'], 'rows' => $sampleNewOrders]),
            'status_movements_table'  => self::renderTable(['headers' => ['Order #', 'Customer', 'Status Name / Remark', 'Changed At'], 'rows' => $sampleMovements]),
            'completed_orders_table'  => self::renderTable(['headers' => ['Order #', 'Customer', 'Completion Time', 'Total', 'Delivery Date'], 'rows' => [["<strong>ORD-10003</strong>", "XYZ Corp", "02:15 PM", "₹18,000.00", "23 Sep 2026"]]]),
            'cancelled_orders_table'  => self::renderTable(['headers' => ['Order #', 'Customer', 'Cancellation Time', 'Total'], 'rows' => [["<strong>ORD-09988</strong>", "Acme Inc", "10:45 AM", "₹2,500.00"]]]),
            'production_orders_table' => self::renderTable(['headers' => ['Order #', 'Customer', 'Status', 'Order Date', 'Delivery Date'], 'rows' => [["<strong>ORD-10001</strong>", "John Doe", "<span style='color:#d97706;font-weight:bold;'>In Production</span>", "23 Sep 2026", "28 Sep 2026"]]]),
            'pending_orders_table'    => self::renderTable(['headers' => ['Order #', 'Customer', 'Created Date', 'Order Age', 'Total'], 'rows' => [["<strong>ORD-10002</strong>", "ABC Electronics", "23 Sep 2026", "<span style='color:#dc2626;font-weight:bold;'>Today</span>", "₹12,500.00"]]]),
            'film_not_applied_table'  => self::renderTable(['headers' => ['Order #', 'Customer', 'Current Status', 'Film Applied'], 'rows' => [["<strong>ORD-10001</strong>", "John Doe", "In Production", "<span style='color:#dc2626;font-weight:bold;'>No (Film Not Applied)</span>"]]]),
        ];
    }

    /**
     * Dummy sample variables for preview / test emails.
     */
    public static function getSampleInventoryReportVariables(string $dateStr): array
    {
        $sampleStock = [
            ["<strong>PCB Board 4 Layer</strong>", "<code>PCB-4L-STM32</code>", "150", "50", "<span style='color:#059669;font-weight:bold;'>In Stock</span>"],
            ["<strong>Copper Cladding Sheet 1.6mm</strong>", "<code>COP-SHEET-16</code>", "8", "15", "<span style='color:#d97706;font-weight:bold;'>Low Stock</span>"],
            ["<strong>FR4 Substrate Board 2 Layer</strong>", "<code>FR4-SUB-2L</code>", "0", "20", "<span style='color:#dc2626;font-weight:bold;'>Out of Stock</span>"],
        ];

        $sampleLogs = [
            ["09:10 AM", "PCB Board 4 Layer", "<code>PCB-4L-STM32</code>", "<span style='color:#059669;font-weight:bold;'>Stock In</span>", "100", "50", "150", "Admin Administrator"],
            ["02:30 PM", "Copper Cladding Sheet", "<code>COP-SHEET-16</code>", "<span style='color:#dc2626;font-weight:bold;'>Stock Out</span>", "7", "15", "8", "Production Operator"],
        ];

        return [
            'report_date'            => $dateStr,
            'report_start'           => $dateStr . ', 12:00 AM',
            'report_end'             => $dateStr . ', 11:59 PM',
            'total_inventory_items'  => '150',
            'items_with_stock'       => '120',
            'low_stock_count'        => '15',
            'out_of_stock_count'     => '15',
            'items_with_movement'    => '32',
            'total_stock_added'      => '450',
            'total_stock_removed'    => '275',
            'total_stock_adjusted'   => '3',
            'current_stock_table'    => self::renderTable(['headers' => ['Product Name', 'SKU', 'Available Stock', 'Min Threshold', 'Status'], 'rows' => $sampleStock]),
            'stock_movements_table'  => self::renderTable(['headers' => ['Time', 'Product', 'SKU', 'Type', 'Qty', 'Prev Qty', 'New Qty', 'Updated By'], 'rows' => $sampleLogs]),
            'stock_added_table'      => self::renderTable(['headers' => ['Product', 'SKU', 'Qty Added', 'Prev Stock', 'New Stock', 'Time', 'User'], 'rows' => [["<strong>PCB Board 4 Layer</strong>", "<code>PCB-4L-STM32</code>", "<span style='color:#059669;font-weight:bold;'>+100</span>", "50", "150", "09:10 AM", "Admin Administrator"]]]),
            'stock_removed_table'    => self::renderTable(['headers' => ['Product', 'SKU', 'Qty Removed', 'Prev Stock', 'New Stock', 'Time', 'User'], 'rows' => [["<strong>Copper Cladding Sheet</strong>", "<code>COP-SHEET-16</code>", "<span style='color:#dc2626;font-weight:bold;'>-7</span>", "15", "8", "02:30 PM", "Production Operator"]]]),
            'adjustments_table'      => self::renderTable(['headers' => ['Product', 'SKU', 'Prev Stock', 'Adjustment', 'New Stock', 'Note / Reason', 'User'], 'rows' => [["<strong>Resistor 10k 0805</strong>", "<code>RES-10K-0805</code>", "500", "-10", "490", "Quality wastage inspection", "Admin Administrator"]]]),
            'low_stock_table'        => self::renderTable(['headers' => ['Product', 'SKU', 'Current Stock', 'Min Threshold', 'Shortage'], 'rows' => [["<strong>Copper Cladding Sheet</strong>", "<code>COP-SHEET-16</code>", "<span style='color:#d97706;font-weight:bold;'>8</span>", "15", "<span style='color:#dc2626;font-weight:bold;'>7 Pcs</span>"]]]),
            'out_of_stock_table'     => self::renderTable(['headers' => ['Product', 'SKU', 'Current Stock', 'Min Threshold'], 'rows' => [["<strong>FR4 Substrate Board 2 Layer</strong>", "<code>FR4-SUB-2L</code>", "<span style='color:#dc2626;font-weight:bold;'>0 (Out of Stock)</span>", "20"]]]),
        ];
    }
}

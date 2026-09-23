<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('key', 'daily_order_progress_report')->doesntExist()) {
            DB::table('email_templates')->insert([
                'key'        => 'daily_order_progress_report',
                'name'       => 'Daily Order Progress Report',
                'subject'    => 'Daily Order Progress Report - {{report_date}}',
                'body'       => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">📊 Daily Order Progress Report ({{report_date}})</h2>
<p>Hello,</p>
<p>Here is the summary of order activity for <strong>{{company_name}}</strong> on <strong>{{report_date}}</strong>.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border: 1px solid #e5e7eb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Total Active Orders:</td>
        <td style="padding: 10px; font-weight: bold;">{{total_orders}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">New Orders Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #2563eb;">{{new_orders_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Orders Completed Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #059669;">{{completed_orders_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Orders Cancelled Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #dc2626;">{{cancelled_orders_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Orders In Production:</td>
        <td style="padding: 10px; font-weight: bold; color: #d97706;">{{production_orders_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Pending Orders:</td>
        <td style="padding: 10px; font-weight: bold;">{{pending_orders_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Total Order Value Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #059669;">{{total_order_value}}</td>
    </tr>
</table>

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">🆕 New Orders Today</h3>
{{new_orders_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">🔄 Order Status Movements Today</h3>
{{status_movements_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">✅ Orders Completed Today</h3>
{{completed_orders_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">❌ Cancelled Orders Today</h3>
{{cancelled_orders_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">⚙️ Orders Currently In Production</h3>
{{production_orders_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">⏳ Pending Orders</h3>
{{pending_orders_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">⚠️ Film Not Applied Alerts</h3>
{{film_not_applied_table}}',
                'to'         => '{{company_email}}',
                'from_email' => null,
                'from_name'  => '{{company_name}} Reports',
                'cc'         => null,
                'bcc'        => null,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('email_templates')->where('key', 'daily_inventory_report')->doesntExist()) {
            DB::table('email_templates')->insert([
                'key'        => 'daily_inventory_report',
                'name'       => 'Daily Inventory & Stock Movement Report',
                'subject'    => 'Daily Inventory & Stock Movement Report - {{report_date}}',
                'body'       => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">📦 Daily Inventory & Stock Movement Report ({{report_date}})</h2>
<p>Hello,</p>
<p>Here is the daily inventory and stock movement overview for <strong>{{company_name}}</strong> on <strong>{{report_date}}</strong>.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border: 1px solid #e5e7eb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Total Inventory Items:</td>
        <td style="padding: 10px; font-weight: bold;">{{total_inventory_items}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Items With Stock:</td>
        <td style="padding: 10px; font-weight: bold; color: #059669;">{{items_with_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Low Stock Items:</td>
        <td style="padding: 10px; font-weight: bold; color: #d97706;">{{low_stock_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Out of Stock Items:</td>
        <td style="padding: 10px; font-weight: bold; color: #dc2626;">{{out_of_stock_count}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Items With Movement Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #2563eb;">{{items_with_movement}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Total Stock Added Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #059669;">+{{total_stock_added}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Total Stock Removed Today:</td>
        <td style="padding: 10px; font-weight: bold; color: #dc2626;">-{{total_stock_removed}}</td>
    </tr>
</table>

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">📊 Current Stock Overview</h3>
{{current_stock_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">🔄 Stock Movements Today</h3>
{{stock_movements_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">📥 Stock Added Today</h3>
{{stock_added_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">📤 Stock Removed Today</h3>
{{stock_removed_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">✏️ Inventory Adjustments Today</h3>
{{adjustments_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">⚠️ Low Stock Items</h3>
{{low_stock_table}}

<h3 style="color: #374151; font-size: 16px; font-weight: 700; margin-top: 24px; margin-bottom: 12px;">🚨 Out of Stock Items</h3>
{{out_of_stock_table}}',
                'to'         => '{{company_email}}',
                'from_email' => null,
                'from_name'  => '{{company_name}} Reports',
                'cc'         => null,
                'bcc'        => null,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', ['daily_order_progress_report', 'daily_inventory_report'])->delete();
    }
};

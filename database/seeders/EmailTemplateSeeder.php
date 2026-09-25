<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmailTemplate;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'key' => 'order_placed',
                'name' => 'Order Placed',
                'subject' => 'Order {{order_number}} has been placed successfully',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Order Confirmation</h2>
<p>Hello <strong>{{customer_name}}</strong>,</p>
<p>Thank you for placing your order with <strong>{{company_name}}</strong>. Your order has been successfully received and is being processed.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Order Number:</td>
        <td style="padding: 10px;">{{order_number}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Date:</td>
        <td style="padding: 10px;">{{order_date}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Gerber File Name:</td>
        <td style="padding: 10px;">{{gerber_file_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Delivery Date:</td>
        <td style="padding: 10px;">{{delivery_date}}</td>
    </tr>
</table>

<p style="text-align: center; margin-top: 25px;">
    <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
</p>',
                'cc' => null,
                'bcc' => null,
                'to' => '{{customer_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'is_active' => true,
            ],
            [
                'key' => 'order_completed',
                'name' => 'Order Completed',
                'subject' => 'Your order {{order_number}} has been delivered',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Order Status Update</h2>
<p>Hello <strong>{{customer_name}}</strong>,</p>
<p>Good news! Your order has been completed/delivered successfully.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Order Number:</td>
        <td style="padding: 10px;">{{order_number}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Date:</td>
        <td style="padding: 10px;">{{order_date}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Gerber File Name:</td>
        <td style="padding: 10px;">{{gerber_file_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Delivery Date:</td>
        <td style="padding: 10px;">{{delivery_date}}</td>
    </tr>
</table>

<p style="text-align: center; margin-top: 25px;">
    <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
</p>',
                'to' => '{{customer_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'order_production_film_not_applied',
                'name' => 'Order Moved to Production - Film Not Applied',
                'subject' => 'Order {{order_number}} moved to production - Film not applied',
                'body' => '<h2 style="color: #d97706; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">⚠️ Order Production Alert - Film Not Applied</h2>
<p>Hello <strong>{{customer_name}}</strong>,</p>
<p>Your order has been moved from <strong>{{previous_order_status}}</strong> to <strong>{{order_status}}</strong> and production processing has started.</p>
<p style="color: #dc2626; font-weight: bold;">However, the film has not been applied to this order yet.</p>
<p>Please review the order and take the necessary action.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Order Number:</td>
        <td style="padding: 10px;">{{order_number}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Previous Status:</td>
        <td style="padding: 10px;">{{previous_order_status}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Current Status:</td>
        <td style="padding: 10px; font-weight: bold; color: #10b981;">{{order_status}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Film Applied:</td>
        <td style="padding: 10px; font-weight: bold; color: #dc2626;">{{film_applied}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Date:</td>
        <td style="padding: 10px;">{{order_date}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Total:</td>
        <td style="padding: 10px;">{{order_total}}</td>
    </tr>
</table>

<p style="text-align: center; margin-top: 25px;">
    <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
</p>',
                'to' => '{{customer_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'order_status_updated',
                'name' => 'Order Status Updated',
                'subject' => 'Your order {{order_number}} status has been updated to {{order_status}}',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Order Status Updated</h2>
<p>Hello <strong>{{customer_name}}</strong>,</p>
<p>We wanted to let you know that the status of your order has been updated.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Order Number:</td>
        <td style="padding: 10px;">{{order_number}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Previous Status:</td>
        <td style="padding: 10px;">{{previous_order_status}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Current Status:</td>
        <td style="padding: 10px; font-weight: bold; color: #10b981;">{{order_status}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Date:</td>
        <td style="padding: 10px;">{{order_date}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Order Total:</td>
        <td style="padding: 10px;">{{order_total}}</td>
    </tr>
</table>

<p>Your order is currently being processed according to the latest status shown above.</p>

<p style="text-align: center; margin-top: 25px;">
    <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
</p>',
                'to' => '{{customer_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'daily_order_progress_report',
                'name' => 'Daily Order Progress Report',
                'subject' => 'Daily Order Progress Report - {{report_date}}',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">📊 Daily Order Progress Report ({{report_date}})</h2>
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
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}} Reports',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'daily_inventory_report',
                'name' => 'Daily Inventory & Stock Movement Report',
                'subject' => 'Daily Inventory & Stock Movement Report - {{report_date}}',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">📦 Daily Inventory & Stock Movement Report ({{report_date}})</h2>
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
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}} Reports',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'inventory_low_stock',
                'name' => 'Inventory Low Stock',
                'subject' => 'Low Stock Alert: {{product_name}}',
                'body' => '<h2 style="color: #d97706; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">⚠️ Low Stock Alert</h2>
<p>Hello,</p>
<p>This is an inventory alert for <strong>{{company_name}}</strong>.</p>
<p>The following item has reached or dropped below its configured low-stock threshold:</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #fffbe6; border: 1px solid #fde68a;">
    <tr style="border-bottom: 1px solid #fef3c7;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Product:</td>
        <td style="padding: 10px;">{{product_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fef3c7;">
        <td style="padding: 10px; font-weight: bold;">SKU:</td>
        <td style="padding: 10px;">{{sku}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fef3c7;">
        <td style="padding: 10px; font-weight: bold;">Current Stock:</td>
        <td style="padding: 10px; color: #d97706; font-weight: bold;">{{current_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fef3c7;">
        <td style="padding: 10px; font-weight: bold;">Minimum Stock Level:</td>
        <td style="padding: 10px;">{{minimum_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fef3c7;">
        <td style="padding: 10px; font-weight: bold;">Status:</td>
        <td style="padding: 10px; font-weight: bold; color: #d97706;">{{inventory_status}}</td>
    </tr>
</table>

<p>Please review the inventory and consider restocking this item promptly.</p>',
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'inventory_out_of_stock',
                'name' => 'Inventory Out of Stock',
                'subject' => 'Out of Stock Alert: {{product_name}}',
                'body' => '<h2 style="color: #dc2626; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">🚨 Out of Stock Alert</h2>
<p>Hello,</p>
<p>This is an urgent inventory alert for <strong>{{company_name}}</strong>.</p>
<p>The following inventory item is currently completely out of stock:</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #fef2f2; border: 1px solid #fecaca;">
    <tr style="border-bottom: 1px solid #fee2e2;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Product:</td>
        <td style="padding: 10px;">{{product_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fee2e2;">
        <td style="padding: 10px; font-weight: bold;">SKU:</td>
        <td style="padding: 10px;">{{sku}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fee2e2;">
        <td style="padding: 10px; font-weight: bold;">Current Stock:</td>
        <td style="padding: 10px; color: #dc2626; font-weight: bold;">{{current_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fee2e2;">
        <td style="padding: 10px; font-weight: bold;">Available Stock:</td>
        <td style="padding: 10px; color: #dc2626; font-weight: bold;">{{available_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #fee2e2;">
        <td style="padding: 10px; font-weight: bold;">Status:</td>
        <td style="padding: 10px; font-weight: bold; color: #dc2626;">{{inventory_status}}</td>
    </tr>
</table>

<p>The item requires immediate restocking. Please review inventory urgently.</p>',
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'inventory_stock_added',
                'name' => 'Inventory Stock Added',
                'subject' => 'Inventory Stock Added: {{product_name}}',
                'body' => '<h2 style="color: #059669; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">📦 Inventory Stock Added</h2>
<p>Hello,</p>
<p>New inventory stock has been added to <strong>{{company_name}}</strong>.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ecfdf5; border: 1px solid #a7f3d0;">
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Product:</td>
        <td style="padding: 10px;">{{product_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">SKU:</td>
        <td style="padding: 10px;">{{sku}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Previous Stock:</td>
        <td style="padding: 10px;">{{previous_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Quantity Added:</td>
        <td style="padding: 10px; color: #059669; font-weight: bold;">+{{quantity_added}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Current Stock:</td>
        <td style="padding: 10px; font-weight: bold;">{{current_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Updated By:</td>
        <td style="padding: 10px;">{{updated_by}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Date:</td>
        <td style="padding: 10px;">{{inventory_date}}</td>
    </tr>
</table>',
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'inventory_stock_adjusted',
                'name' => 'Inventory Stock Adjusted',
                'subject' => 'Inventory Adjusted: {{product_name}}',
                'body' => '<h2 style="color: #2563eb; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">✏️ Inventory Adjusted</h2>
<p>Hello,</p>
<p>An inventory quantity has been manually adjusted for <strong>{{company_name}}</strong>.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #eff6ff; border: 1px solid #bfdbfe;">
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Product:</td>
        <td style="padding: 10px;">{{product_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">SKU:</td>
        <td style="padding: 10px;">{{sku}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Previous Stock:</td>
        <td style="padding: 10px;">{{previous_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Adjustment:</td>
        <td style="padding: 10px; font-weight: bold;">{{adjustment_quantity}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Current Stock:</td>
        <td style="padding: 10px; font-weight: bold;">{{current_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Reason / Note:</td>
        <td style="padding: 10px;">{{adjustment_reason}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Updated By:</td>
        <td style="padding: 10px;">{{updated_by}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #dbeafe;">
        <td style="padding: 10px; font-weight: bold;">Date:</td>
        <td style="padding: 10px;">{{inventory_date}}</td>
    </tr>
</table>',
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'inventory_restocked',
                'name' => 'Inventory Restocked',
                'subject' => 'Inventory Restocked: {{product_name}}',
                'body' => '<h2 style="color: #059669; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">🚚 Inventory Restocked</h2>
<p>Hello,</p>
<p>The following inventory item has been restocked for <strong>{{company_name}}</strong>.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ecfdf5; border: 1px solid #a7f3d0;">
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Product:</td>
        <td style="padding: 10px;">{{product_name}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">SKU:</td>
        <td style="padding: 10px;">{{sku}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Previous Stock:</td>
        <td style="padding: 10px;">{{previous_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Quantity Received:</td>
        <td style="padding: 10px; color: #059669; font-weight: bold;">+{{quantity_added}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Current Stock:</td>
        <td style="padding: 10px; font-weight: bold;">{{current_stock}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Received By:</td>
        <td style="padding: 10px;">{{updated_by}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #d1fae5;">
        <td style="padding: 10px; font-weight: bold;">Date:</td>
        <td style="padding: 10px;">{{inventory_date}}</td>
    </tr>
</table>',
                'to' => '{{company_email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'password_reset_otp',
                'name' => 'Password Reset Verification Code (OTP)',
                'subject' => 'Your Megabyte password reset verification code',
                'body' => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Password Reset Code</h2>
<p>Hello <strong>{{name}}</strong>,</p>
<p>We received a request to reset your password for your <strong>{{company_name}}</strong> account.</p>
<p>Your verification code is:</p>
<div style="background-color: #f3f4f6; border: 2px dashed #10b981; padding: 16px; text-align: center; border-radius: 8px; margin: 20px 0;">
    <span style="font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #047857; font-family: monospace;">{{otp}}</span>
</div>
<p>This code will expire in <strong>{{otp_expiry_minutes}}</strong> minutes.</p>
<p style="color: #6b7280; font-size: 13px;">If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>',
                'to' => '{{email}}',
                'from_email' => null,
                'from_name' => '{{company_name}} Security',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'password_reset_success',
                'name' => 'Password Reset Success Notification',
                'subject' => 'Your Megabyte password has been updated',
                'body' => '<h2 style="color: #059669; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Password Updated Successfully</h2>
<p>Hello <strong>{{name}}</strong>,</p>
<p>Your password for your <strong>{{company_name}}</strong> account was successfully updated.</p>
<p style="background-color: #ecfdf5; border-left: 4px solid #10b981; padding: 12px; margin: 20px 0; color: #065f46; font-size: 14px;">
    If you made this change, no further action is needed. You can now sign in with your new password.
</p>
<p style="color: #dc2626; font-size: 13px; font-weight: bold;">If you did not make this change, please contact our support team immediately.</p>',
                'to' => '{{email}}',
                'from_email' => null,
                'from_name' => '{{company_name}} Security',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'client_welcome',
                'name' => 'Client Welcome Email',
                'subject' => 'Welcome to Megabyte Circuits',
                'body' => '<h2 style="color: #10b981; font-size: 22px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Welcome to Megabyte Circuits!</h2>
<p>Hello <strong>{{name}}</strong>,</p>
<p>Welcome to <strong>{{company_name}}</strong>! Your client account has been successfully created.</p>
<p>You can now sign in to your dashboard to upload Gerber files, calculate instant PCB quotes, and track orders in real-time.</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border: 1px solid #e5e7eb;">
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold; width: 40%;">Account Email:</td>
        <td style="padding: 10px;">{{email}}</td>
    </tr>
    <tr style="border-bottom: 1px solid #e5e7eb;">
        <td style="padding: 10px; font-weight: bold;">Status:</td>
        <td style="padding: 10px; color: #059669; font-weight: bold;">Active</td>
    </tr>
</table>

<p style="text-align: center; margin-top: 25px;">
    <a href="{{login_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">Sign In to Your Account</a>
</p>',
                'to' => '{{email}}',
                'from_email' => null,
                'from_name' => '{{company_name}}',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ]
        ];

        foreach ($templates as $tpl) {
            // Use firstOrCreate so existing admin customizations are preserved
            EmailTemplate::firstOrCreate(
                ['key' => $tpl['key']],
                $tpl
            );
        }
    }
}


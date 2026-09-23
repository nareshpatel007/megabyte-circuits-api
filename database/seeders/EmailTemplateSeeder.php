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

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

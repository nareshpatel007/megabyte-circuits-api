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
        if (DB::table('email_templates')->where('key', 'order_status_updated')->doesntExist()) {
            DB::table('email_templates')->insert([
                'key'        => 'order_status_updated',
                'name'       => 'Order Status Updated',
                'subject'    => 'Your order {{order_number}} status has been updated to {{order_status}}',
                'body'       => '<h2 style="color: #111827; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Order Status Updated</h2>
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
                'to'         => '{{customer_email}}',
                'from_email' => null,
                'from_name'  => '{{company_name}}',
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
        DB::table('email_templates')->where('key', 'order_status_updated')->delete();
    }
};

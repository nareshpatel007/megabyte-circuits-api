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
        if (DB::table('email_templates')->where('key', 'order_production_film_not_applied')->doesntExist()) {
            DB::table('email_templates')->insert([
                'key'        => 'order_production_film_not_applied',
                'name'       => 'Order Moved to Production - Film Not Applied',
                'subject'    => 'Order {{order_number}} moved to production - Film not applied',
                'body'       => '<h2 style="color: #d97706; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">⚠️ Order Production Alert - Film Not Applied</h2>
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
        DB::table('email_templates')->where('key', 'order_production_film_not_applied')->delete();
    }
};

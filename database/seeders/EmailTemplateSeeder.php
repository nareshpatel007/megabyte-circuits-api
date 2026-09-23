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
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
    <div style="text-align: center; margin-bottom: 20px;">
        <h2 style="color: #10b981; margin: 0;">{{company_name}}</h2>
        <p style="color: #666; font-size: 14px;">Order Confirmation</p>
    </div>
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
            <td style="padding: 10px; font-weight: bold;">Board Name:</td>
            <td style="padding: 10px;">{{board_name}}</td>
        </tr>
        <tr style="border-bottom: 1px solid #e5e7eb;">
            <td style="padding: 10px; font-weight: bold;">Order Status:</td>
            <td style="padding: 10px;"><span style="background-color: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-weight: bold;">{{order_status}}</span></td>
        </tr>
        <tr>
            <td style="padding: 10px; font-weight: bold;">Total Amount:</td>
            <td style="padding: 10px; font-weight: bold; color: #10b981;">{{order_total}}</td>
        </tr>
    </table>

    <p style="text-align: center; margin-top: 25px;">
        <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
    </p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 25px 0;" />
    <p style="color: #888; font-size: 12px; text-align: center;">If you have any questions, please feel free to reply to this email.<br />Thank you for choosing {{company_name}}.</p>
</div>',
                'cc' => null,
                'bcc' => null,
                'is_active' => true,
            ],
            [
                'key' => 'order_completed',
                'name' => 'Order Completed',
                'subject' => 'Your order {{order_number}} has been delivered',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
    <div style="text-align: center; margin-bottom: 20px;">
        <h2 style="color: #10b981; margin: 0;">{{company_name}}</h2>
        <p style="color: #666; font-size: 14px;">Order Status Update</p>
    </div>
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
            <td style="padding: 10px; font-weight: bold;">Board Name:</td>
            <td style="padding: 10px;">{{board_name}}</td>
        </tr>
        <tr style="border-bottom: 1px solid #e5e7eb;">
            <td style="padding: 10px; font-weight: bold;">Status:</td>
            <td style="padding: 10px;"><span style="background-color: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-weight: bold;">{{order_status}}</span></td>
        </tr>
        <tr>
            <td style="padding: 10px; font-weight: bold;">Order Total:</td>
            <td style="padding: 10px; font-weight: bold; color: #10b981;">{{order_total}}</td>
        </tr>
    </table>

    <p style="text-align: center; margin-top: 25px;">
        <a href="{{order_url}}" style="background-color: #10b981; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: inline-block;">View Order Details</a>
    </p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 25px 0;" />
    <p style="color: #888; font-size: 12px; text-align: center;">We appreciate your business. Thank you for choosing {{company_name}}.</p>
</div>',
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

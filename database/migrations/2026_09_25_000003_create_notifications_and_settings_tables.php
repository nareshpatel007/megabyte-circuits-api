<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create Notifications Table
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->string('recipient_type', 20)->default('user'); // 'user' or 'admin'
                $table->unsignedBigInteger('recipient_id')->nullable()->index(); // null means all admins or broadcast
                $table->string('event_key', 100)->index();
                $table->string('category', 50)->default('system')->index(); // order, payment, gerber, inventory, system, support
                $table->string('title', 255);
                $table->text('message');
                $table->string('icon', 50)->nullable();
                $table->string('theme', 20)->default('info'); // success, info, warning, error
                $table->string('action_url', 500)->nullable();
                $table->string('entity_type', 50)->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->boolean('is_read')->default(false)->index();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['recipient_type', 'recipient_id', 'is_read']);
                $table->index(['created_at']);
            });
        }

        // 2. Create Notification Settings Table
        if (!Schema::hasTable('notification_settings')) {
            Schema::create('notification_settings', function (Blueprint $table) {
                $table->id();
                $table->string('event_key', 100)->unique();
                $table->string('event_name', 150);
                $table->string('category', 50);
                $table->text('description')->nullable();
                $table->boolean('client_enabled')->default(true);
                $table->boolean('admin_enabled')->default(true);
                $table->boolean('realtime_enabled')->default(true);
                $table->boolean('toast_enabled')->default(true);
                $table->boolean('email_enabled')->default(false);
                $table->string('priority', 20)->default('normal'); // low, normal, high, critical
                $table->string('recipient_permission', 100)->nullable();
                $table->timestamps();
            });

            // Seed default notification event configurations
            $defaultEvents = [
                // Order events
                [
                    'event_key' => 'order.created',
                    'event_name' => 'New Order Placed',
                    'category' => 'order',
                    'description' => 'Triggered when a customer successfully places a PCB order.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => true,
                    'priority' => 'high',
                    'recipient_permission' => 'orders.view',
                ],
                [
                    'event_key' => 'order.reorder',
                    'event_name' => 'PCB Order Repeated / Reordered',
                    'category' => 'order',
                    'description' => 'Triggered when a client reorders a previous PCB project.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => false,
                    'priority' => 'normal',
                    'recipient_permission' => 'orders.view',
                ],
                [
                    'event_key' => 'order.status_updated',
                    'event_name' => 'Order Status Changed',
                    'category' => 'order',
                    'description' => 'Triggered when an order status is updated (e.g. Processing, In Production).',
                    'client_enabled' => true,
                    'admin_enabled' => false,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => true,
                    'priority' => 'normal',
                    'recipient_permission' => null,
                ],
                [
                    'event_key' => 'order.completed',
                    'event_name' => 'Order Completed / Delivered',
                    'category' => 'order',
                    'description' => 'Triggered when an order is completed or shipped to customer.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => true,
                    'priority' => 'high',
                    'recipient_permission' => 'orders.view',
                ],

                // Payment events
                [
                    'event_key' => 'payment.successful',
                    'event_name' => 'Payment Successful',
                    'category' => 'payment',
                    'description' => 'Triggered when Razorpay/Gateway payment verification succeeds.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => true,
                    'priority' => 'high',
                    'recipient_permission' => 'payments.view',
                ],
                [
                    'event_key' => 'payment.failed',
                    'event_name' => 'Payment Failed',
                    'category' => 'payment',
                    'description' => 'Triggered when a payment attempt fails or is declined.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => false,
                    'priority' => 'critical',
                    'recipient_permission' => 'payments.view',
                ],

                // Gerber events
                [
                    'event_key' => 'gerber.uploaded',
                    'event_name' => 'Gerber File Uploaded',
                    'category' => 'gerber',
                    'description' => 'Triggered when a customer uploads a new Gerber zip archive.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => false,
                    'priority' => 'normal',
                    'recipient_permission' => 'gerber.view',
                ],
                [
                    'event_key' => 'gerber.processed',
                    'event_name' => 'Gerber Analysis Completed',
                    'category' => 'gerber',
                    'description' => 'Triggered when automated Python Gerber analyzer finishes extracting PCB parameters.',
                    'client_enabled' => true,
                    'admin_enabled' => false,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => false,
                    'priority' => 'low',
                    'recipient_permission' => null,
                ],

                // JLCPCB events
                [
                    'event_key' => 'jlcpcb.quotation_generated',
                    'event_name' => 'JLCPCB Quote Calculated',
                    'category' => 'gerber',
                    'description' => 'Triggered when JLCPCB API quote calculation completes.',
                    'client_enabled' => true,
                    'admin_enabled' => false,
                    'realtime_enabled' => true,
                    'toast_enabled' => false,
                    'email_enabled' => false,
                    'priority' => 'low',
                    'recipient_permission' => null,
                ],

                // Inventory events
                [
                    'event_key' => 'inventory.low_stock',
                    'event_name' => 'Low Stock Warning',
                    'category' => 'inventory',
                    'description' => 'Triggered when inventory item quantity drops below minimum threshold.',
                    'client_enabled' => false,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => true,
                    'priority' => 'high',
                    'recipient_permission' => 'inventory.view',
                ],

                // Account / User events
                [
                    'event_key' => 'user.registered',
                    'event_name' => 'New Customer Registration',
                    'category' => 'system',
                    'description' => 'Triggered when a new user registers an account.',
                    'client_enabled' => true,
                    'admin_enabled' => true,
                    'realtime_enabled' => true,
                    'toast_enabled' => true,
                    'email_enabled' => false,
                    'priority' => 'normal',
                    'recipient_permission' => 'clients.view',
                ]
            ];

            foreach ($defaultEvents as $evt) {
                DB::table('notification_settings')->insert(array_merge($evt, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};

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
        // 1. User Addresses Table with SoftDeletes
        Schema::dropIfExists('pcb_order_meta');
        Schema::dropIfExists('pcb_orders');
        Schema::dropIfExists('user_addresses');
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('address_type')->default('shipping'); // shipping / billing
            $table->string('customer_type')->default('individual'); // company / individual
            $table->string('company_name')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('country')->default('India');
            $table->string('state');
            $table->string('city');
            $table->string('street_address');
            $table->string('building_no')->nullable();
            $table->string('postal_code');
            $table->string('mobile');
            $table->boolean('is_default')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('address_type');
        });

        // 2. Payment Transactions Table
        Schema::dropIfExists('payment_transactions');
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_number')->unique();
            $table->string('razorpay_payment_id')->nullable();
            $table->string('razorpay_order_id')->nullable();
            $table->string('razorpay_signature')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('INR');
            $table->string('status', 50)->default('pending');
            $table->string('payment_method')->nullable();
            $table->json('payload')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('transaction_number');
        });

        // 3. PCB Order Statuses Table
        Schema::dropIfExists('pcb_order_statuses');
        Schema::create('pcb_order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed all status options
        $statuses = [
            'Pending', 'Hold', 'Cam', 'Cam done', 'Filming', 'Traveler',
            'Drilling', 'Outside Drill', 'Drill Done', 'Blackhole', 'DH',
            'DH Done', 'Under Process', 'DH Exposer', 'Final Cutting', 'Devloping',
            'Plating', 'Plating QC', 'Devloping QC', 'Etching', 'Etch QC',
            'Masking', 'Masking Exposer', 'HAL/Tin', 'Silk', 'Vgroove',
            'Rout', 'Rout Done', 'Ready to ship', 'BBT', 'BBT-MQC',
            'Final QC', 'move', 'FPT', 'Completed', 'Cancelled'
        ];

        foreach ($statuses as $index => $name) {
            DB::table('pcb_order_statuses')->insert([
                'name' => $name,
                'label' => $name,
                'sort_order' => $index + 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // 4. Gerber Files Table
        Schema::dropIfExists('gerber_files');
        Schema::create('gerber_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('original_name');
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('file_size')->nullable();
            $table->string('board_name')->nullable();
            $table->longText('preview_data')->nullable(); // Gerber SVG or thumbnail
            $table->timestamps();
            $table->softDeletes(); // Enable SoftDeletes

            $table->index('user_id');
        });

        // 5. Fully Normalized PCB Orders Table (No board_name, No text status)
        Schema::create('pcb_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->unsignedBigInteger('user_id')->nullable(); // Foreign Key ID -> users.id
            $table->unsignedBigInteger('transaction_id')->nullable(); // Foreign Key ID -> payment_transactions.id
            $table->unsignedBigInteger('shipping_address_id')->nullable(); // Foreign Key ID -> user_addresses.id
            $table->unsignedBigInteger('billing_address_id')->nullable(); // Foreign Key ID -> user_addresses.id
            $table->unsignedBigInteger('status_id')->nullable(); // Foreign Key ID -> pcb_order_statuses.id
            $table->unsignedBigInteger('gerber_file_id')->nullable(); // Foreign Key ID -> gerber_files.id
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('order_value', 10, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('transaction_id');
            $table->index('shipping_address_id');
            $table->index('billing_address_id');
            $table->index('status_id');
            $table->index('gerber_file_id');
        });

        // 6. Meta Specifications Table
        Schema::create('pcb_order_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcb_order_id')->constrained('pcb_orders')->onDelete('cascade');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();

            $table->index(['pcb_order_id', 'meta_key']);
        });
        // 7. PCB Order Logs Table
        Schema::dropIfExists('pcb_order_logs');
        Schema::create('pcb_order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pcb_order_id')->nullable();
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('Order Placed');
            $table->string('action')->default('Order Created');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('pcb_order_id');
            $table->index('order_number');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcb_order_logs');
        Schema::dropIfExists('pcb_order_meta');
        Schema::dropIfExists('pcb_orders');
        Schema::dropIfExists('gerber_files');
        Schema::dropIfExists('pcb_order_statuses');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('user_addresses');
    }
};

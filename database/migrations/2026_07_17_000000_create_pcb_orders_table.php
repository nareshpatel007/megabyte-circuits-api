<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcb_orders', function (Blueprint $table) {
            $table->id();
            
            // Basic PCB Specifications
            $table->string('base_material', 50)->default('FR-4');
            $table->integer('layers');
            $table->decimal('width', 10, 2);
            $table->decimal('height', 10, 2);
            $table->string('unit', 10)->default('mm');
            $table->integer('qty');
            $table->string('product_type', 100);
            $table->integer('different_design')->default(1);
            
            // PCB Specifications
            $table->string('thickness', 20)->default('1.6mm');
            $table->string('pcb_color', 20)->default('#52c41a');
            $table->string('silkscreen', 50)->default('White');
            $table->string('material_type', 100)->default('FR4-TG135');
            $table->string('surface_finish', 50)->default('HASL(with lead)');
            
            // High-spec Options
            $table->string('copper_weight', 20)->default('1oz');
            $table->string('via_covering', 100)->default('Not Specified');
            $table->string('via_plating', 100)->default('Not Specified');
            $table->string('min_hole', 50)->default('0.3mm');
            $table->string('tolerance', 50)->default('Regular');
            $table->string('confirm_file', 10)->default('No');
            $table->string('mark_on_pcb', 100)->default('Remove Mark');
            $table->string('elec_test', 100)->default('Flying Probe Fully Test');
            $table->string('gold_fingers', 10)->default('No');
            $table->string('castellated', 10)->default('No');
            $table->string('edge_plating', 10)->default('No');
            $table->string('blind_slots', 10)->default('No');
            $table->string('ul_marking', 100)->default('No');
            $table->string('humidity', 10)->default('No');
            
            // Advanced Options
            $table->string('kelvin_test', 10)->default('No');
            $table->string('paper_between', 10)->default('No');
            $table->string('appearance_quality', 100)->default('IPC Class 2 Standard');
            $table->string('silkscreen_tech', 100)->default('Ink-jet Printing Silkscreen');
            $table->string('inspection_report', 100)->default('No');
            $table->text('pcb_remark')->nullable();
            
            // Additional Options
            $table->boolean('assembly_on')->default(false);
            $table->boolean('stencil_on')->default(false);
            $table->string('build_time', 50)->default('2 days');
            
            // Customer Information
            $table->string('board_name');
            $table->string('user_mobile', 15);
            $table->string('user_email');
            $table->string('gst_number', 50)->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            
            // Pricing Information
            $table->integer('lead_time_days');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('order_value', 10, 2);
            $table->date('delivery_date');
            $table->decimal('total_area_sqm', 10, 4);
            
            // File Upload
            $table->string('gerber_file_url')->nullable();
            $table->string('gerber_file_name')->nullable();
            $table->string('gerber_file_size')->nullable();
            
            // Order Status
            $table->string('status', 50)->default('pending');
            $table->string('order_number', 50)->unique();
            
            // Metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('user_email');
            $table->index('user_mobile');
            $table->index('status');
            $table->index('order_number');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_orders');
    }
};

<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

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

echo "PCB_ORDER_LOGS_TABLE_CREATED_SUCCESSFULLY\n";

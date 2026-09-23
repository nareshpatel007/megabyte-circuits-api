<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory;

    protected $table = 'email_logs';

    protected $fillable = [
        'template_key',
        'order_id',
        'inventory_item_id',
        'customer_id',
        'from_email',
        'from_name',
        'to',
        'cc',
        'bcc',
        'subject',
        'status',
        'is_test',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'order_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}

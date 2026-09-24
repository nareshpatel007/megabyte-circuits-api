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
        'email_type',
        'order_id',
        'inventory_item_id',
        'customer_id',
        'from_email',
        'from_name',
        'reply_to',
        'to',
        'cc',
        'bcc',
        'subject',
        'body',
        'text_body',
        'status',
        'provider',
        'provider_message_id',
        'job_id',
        'retry_count',
        'is_test',
        'error_message',
        'sent_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
        'retry_count' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'order_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function customer()
    {
        return $this->belongsTo(PcbUser::class, 'customer_id');
    }

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'template_key', 'key');
    }
}

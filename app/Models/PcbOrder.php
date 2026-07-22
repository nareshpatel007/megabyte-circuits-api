<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcbOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pcb_orders';

    protected $fillable = [
        'user_id',
        'status_id',
        'order_number',
        'board_name',
        'customer_name',
        'user_email',
        'user_mobile',
        'status',
        'unit_price',
        'order_value',
        'delivery_date',
    ];

    // Status relationship
    public function statusDetails()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    // Customer User relationship
    public function user()
    {
        return $this->belongsTo(PcbUser::class, 'user_id');
    }

    protected $casts = [
        'unit_price' => 'decimal:2',
        'order_value' => 'decimal:2',
        'delivery_date' => 'date',
    ];

    // Meta relationship
    public function metas()
    {
        return $this->hasMany(PcbOrderMeta::class, 'pcb_order_id');
    }

    // Helper to get meta key value easily
    public function getMeta($key, $default = null)
    {
        $meta = $this->metas->where('meta_key', $key)->first();
        return $meta ? $meta->meta_value : $default;
    }

    // Status Histories relationship
    public function statusHistories()
    {
        return $this->hasMany(PcbOrderStatusHistory::class, 'pcb_order_id')->with('admin')->orderBy('created_at', 'desc');
    }
}

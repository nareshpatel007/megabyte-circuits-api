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
        'q_no',
        'c_g',
        'combo',
        'board_name',
        'customer_name',
        'user_email',
        'user_mobile',
        'status',
        'unit_price',
        'completed_qty',
        'order_qty',
        'launch_qty',
        'panel_qty',
        'ups_qty',
        'final_qty',
        'failed_qty',
        'order_value',
        'launch_date',
        'delivery_date',
        'bill_number',
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
        'unit_price'  => 'decimal:2',
        'order_value' => 'decimal:2',
        'order_qty'   => 'integer',
        'launch_qty'  => 'integer',
        'panel_qty'   => 'integer',
        'ups_qty'     => 'integer',
        'final_qty'   => 'integer',
        'failed_qty'  => 'integer',
        'launch_date' => 'date',
        'delivery_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($order) {
            $statusChanged = $order->isDirty('status') || $order->isDirty('status_id');
            if ($statusChanged) {
                $oldStatus = strtolower(trim((string)$order->getOriginal('status')));
                $newStatus = strtolower(trim((string)$order->status));

                $wasPending = empty($oldStatus) || $oldStatus === 'pending' || $oldStatus === 'move';
                $isNowPending = empty($newStatus) || $newStatus === 'pending' || $newStatus === 'move';

                if ($wasPending && !$isNowPending) {
                    if (empty($order->launch_date)) {
                        $order->launch_date = now()->toDateString();
                    }
                }
            }
        });

        static::creating(function ($order) {
            $status = strtolower(trim((string)($order->status ?? '')));
            $isPending = empty($status) || $status === 'pending' || $status === 'move';
            if (!$isPending && empty($order->launch_date)) {
                $order->launch_date = now()->toDateString();
            }
        });
    }

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

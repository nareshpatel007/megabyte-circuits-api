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
        'transaction_id',
        'shipping_address_id',
        'billing_address_id',
        'status_id',
        'gerber_file_id',
        'order_number',
        'pn_number',
        'order_type',
        'quotation_source',
        'jlcpcb_file_key',
        'jlcpcb_quotation_snapshot',
        'q_no',
        'c_g',
        'combo',
        'old_order_number',
        'layers',
        'mask',
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
        'film_applied',
        'created_at',
    ];

    // Ensure c_g is stored uppercase or null
    public function setCGAttribute($value)
    {
        $this->attributes['c_g'] = ($value !== null && trim((string)$value) !== '') ? strtoupper(trim((string)$value)) : null;
    }

    // Status relationship
    public function statusDetails()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    // Status Histories relationship
    public function statusHistories()
    {
        return $this->hasMany(PcbOrderStatusHistory::class, 'pcb_order_id')->orderBy('created_at', 'desc');
    }

    // Customer User relationship
    public function user()
    {
        return $this->belongsTo(PcbUser::class, 'user_id');
    }

    // Gerber File relationship
    public function gerberFile()
    {
        return $this->belongsTo(GerberFile::class, 'gerber_file_id');
    }

    // Combo Orders (Child orders under this parent order)
    public function comboOrders()
    {
        return $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_combos',
            'parent_order_id',
            'combo_order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status', 'pcb_orders.pn_number');
    }

    // Combo Parent Record
    public function comboParentRecord()
    {
        return $this->hasOne(PcbOrderCombo::class, 'combo_order_id');
    }

    // Old Order Numbers (Referenced previous orders for this order)
    public function oldOrders()
    {
        return $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_old_orders',
            'order_id',
            'old_order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status', 'pcb_orders.pn_number');
    }

    // Reverse relationship (Orders that reference this order as their old order)
    public function referencedAsOldOrderBy()
    {
        return $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_old_orders',
            'old_order_id',
            'order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status', 'pcb_orders.pn_number');
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
        'launch_date' => 'date:Y-m-d',
        'delivery_date' => 'date:Y-m-d',
        'film_applied' => 'boolean',
        'jlcpcb_quotation_snapshot' => 'array',
    ];

    public function scopeJlcpcb($query)
    {
        return $query->where('order_type', 'jlcpcb')
            ->orWhere('quotation_source', 'jlcpcb')
            ->orWhere('order_number', 'LIKE', 'JL%')
            ->orWhere('order_number', 'LIKE', 'J%');
    }

    public function scopeNormal($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('order_type')
              ->orWhere('order_type', 'normal')
              ->orWhere('order_number', 'LIKE', 'M%');
        });
    }

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

        static::deleting(function ($order) {
            if (method_exists($order, 'isForceDeleting') && $order->isForceDeleting()) {
                return;
            }

            $deletedAt = now();

            // 1. Soft-delete meta data
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_meta') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_meta', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 2. Soft-delete status histories
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_status_histories', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_status_histories')
                    ->where('pcb_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 3. Soft-delete notes
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                    ->where('pcb_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 4. Soft-delete job card documents
            if (\Illuminate\Support\Facades\Schema::hasTable('job_card_documents') && \Illuminate\Support\Facades\Schema::hasColumn('job_card_documents', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('job_card_documents')
                    ->where('pcb_order_id', $order->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 5. Soft-delete combo associations (where order is parent or child)
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_combos') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_combos')
                    ->where(function ($q) use ($order) {
                        $q->where('parent_order_id', $order->id)
                          ->orWhere('combo_order_id', $order->id);
                    })
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 6. Soft-delete old order references (where order is order_id or old_order_id)
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_old_orders') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_old_orders')
                    ->where(function ($q) use ($order) {
                        $q->where('order_id', $order->id)
                          ->orWhere('old_order_id', $order->id);
                    })
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt]);
            }

            // 7. Soft-delete associated payment transaction if no other active order is linked to it
            $transactionId = $order->transaction_id ?: (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'transaction_id') ? \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->value('transaction_id') : null);
            if (!empty($transactionId) && \Illuminate\Support\Facades\Schema::hasTable('payment_transactions') && \Illuminate\Support\Facades\Schema::hasColumn('payment_transactions', 'deleted_at')) {
                $hasOtherActiveOrder = \Illuminate\Support\Facades\DB::table('pcb_orders')
                    ->where('transaction_id', $transactionId)
                    ->where('id', '!=', $order->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$hasOtherActiveOrder) {
                    \Illuminate\Support\Facades\DB::table('payment_transactions')
                        ->where('id', $transactionId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }
            }
        });

        static::restoring(function ($order) {
            // Restore related records if the order is restored
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_meta') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_meta', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->update(['deleted_at' => null]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_status_histories', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_status_histories')
                    ->where('pcb_order_id', $order->id)
                    ->update(['deleted_at' => null]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                    ->where('pcb_order_id', $order->id)
                    ->update(['deleted_at' => null]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('job_card_documents') && \Illuminate\Support\Facades\Schema::hasColumn('job_card_documents', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('job_card_documents')
                    ->where('pcb_order_id', $order->id)
                    ->update(['deleted_at' => null]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_combos') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_combos')
                    ->where(function ($q) use ($order) {
                        $q->where('parent_order_id', $order->id)
                          ->orWhere('combo_order_id', $order->id);
                    })
                    ->update(['deleted_at' => null]);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_old_orders') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('pcb_order_old_orders')
                    ->where(function ($q) use ($order) {
                        $q->where('order_id', $order->id)
                          ->orWhere('old_order_id', $order->id);
                    })
                    ->update(['deleted_at' => null]);
            }

            $transactionId = $order->transaction_id ?: (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'transaction_id') ? \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->value('transaction_id') : null);
            if (!empty($transactionId) && \Illuminate\Support\Facades\Schema::hasTable('payment_transactions') && \Illuminate\Support\Facades\Schema::hasColumn('payment_transactions', 'deleted_at')) {
                \Illuminate\Support\Facades\DB::table('payment_transactions')
                    ->where('id', $transactionId)
                    ->update(['deleted_at' => null]);
            }
        });
    }

    // Payment Transaction relationship
    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    // Notes relationship
    public function notes()
    {
        return $this->hasMany(PcbOrderNote::class, 'pcb_order_id')->orderBy('created_at', 'desc');
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

    // Job Card Documents relationship
    public function jobCardDocuments()
    {
        return $this->hasMany(JobCardDocument::class, 'pcb_order_id')->orderBy('sort_order', 'asc');
    }
}


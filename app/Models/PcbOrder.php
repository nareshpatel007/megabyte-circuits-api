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
        'client_id',
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
        'user_email',
        'user_mobile',
        'unit_price',
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
        'source_order_id',
        'source_order_number',
        'is_reorder',
        'created_at',
    ];

    // Ensure c_g is stored uppercase or null
    public function setCGAttribute($value)
    {
        $this->attributes['c_g'] = ($value !== null && trim((string)$value) !== '') ? strtoupper(trim((string)$value)) : null;
    }

    // Status accessor ensuring status is dynamically resolved from canonical status_id / statusDetails
    public function getStatusAttribute($value = null)
    {
        if ($this->relationLoaded('statusDetails') && $this->getRelation('statusDetails')) {
            return $this->getRelation('statusDetails')->name;
        }

        if (!empty($this->status_id)) {
            $st = \App\Services\OrderStatusResolver::resolve($this->status_id);
            if ($st) {
                return $st->name;
            }
        }

        return $value ?: 'Pending';
    }

    // Status mutator setting canonical status_id dynamically
    public function setStatusAttribute($value)
    {
        if (!empty($value)) {
            $canonical = \App\Services\OrderStatusResolver::resolve($value);
            if ($canonical) {
                $this->attributes['status_id'] = $canonical->id;
                $this->setRelation('statusDetails', $canonical);
            }
        }
    }

    // Customer Name mutator ensuring NO writes to physical pcb_orders and preserving guest snapshot in meta
    public function setCustomerNameAttribute($value)
    {
        if ($this->exists && !empty($value) && empty($this->user_id)) {
            \Illuminate\Support\Facades\DB::table('pcb_order_meta')->updateOrInsert(
                ['pcb_order_id' => $this->id, 'meta_key' => 'customer_name'],
                ['meta_value' => trim((string)$value), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    // Customer Name accessor resolving registered user, or guest/historical meta snapshot
    public function getCustomerNameAttribute($value = null)
    {
        if (!empty($this->user_id)) {
            if ($this->relationLoaded('user') && $this->user) {
                $name = $this->user->company_name ?: ($this->user->name ?: (isset($this->user->first_name) ? trim("{$this->user->first_name} {$this->user->last_name}") : null));
                if (!empty($name)) {
                    return $name;
                }
            } else {
                $u = $this->user;
                if ($u) {
                    $name = $u->company_name ?: ($u->name ?: (isset($u->first_name) ? trim("{$u->first_name} {$u->last_name}") : null));
                    if (!empty($name)) {
                        return $name;
                    }
                }
            }
        }

        $metaCust = $this->relationLoaded('metas')
            ? ($this->getMeta('customer_name') ?: $this->getMeta('client'))
            : (\Illuminate\Support\Facades\DB::table('pcb_order_meta')->where('pcb_order_id', $this->id)->whereIn('meta_key', ['customer_name', 'client'])->value('meta_value'));

        if (!empty($metaCust)) {
            return trim((string)$metaCust);
        }

        if (!empty($value)) {
            return $value;
        }

        if (!empty($this->user_email)) {
            return strtok($this->user_email, '@');
        }

        return 'Guest Customer';
    }

    // Completed Qty alias/fallback to final_qty
    public function getCompletedQtyAttribute($value = null)
    {
        return ($value !== null && (int)$value > 0) ? (int)$value : (int)($this->final_qty ?? 0);
    }

    // Completed Qty mutator syncing canonical final_qty dynamically
    public function setCompletedQtyAttribute($value)
    {
        $intVal = (int)$value;
        $this->attributes['final_qty'] = $intVal;
        if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'completed_qty')) {
            $this->attributes['completed_qty'] = $intVal;
        }
    }

    // Status relationship
    public function statusDetails()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    // Status details accessor ensuring status_details is always canonical
    public function getStatusDetailsAttribute()
    {
        if ($this->relationLoaded('statusDetails')) {
            return $this->getRelation('statusDetails');
        }

        if (!empty($this->status_id)) {
            $st = \App\Services\OrderStatusResolver::resolve($this->status_id);
            if ($st) {
                $this->setRelation('statusDetails', $st);
                return $st;
            }
        }

        if (!empty($this->status)) {
            $st = \App\Services\OrderStatusResolver::resolve($this->status);
            if ($st) {
                $this->setRelation('statusDetails', $st);
                return $st;
            }
        }

        return null;
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

    // Canonical Client relationship alias (Section 2 & 16)
    public function client()
    {
        return $this->user();
    }

    public function getClientIdAttribute()
    {
        return $this->user_id;
    }

    public function setClientIdAttribute($value)
    {
        $this->attributes['user_id'] = $value ? (int)$value : null;
    }

    // Gerber File relationship
    public function gerberFile()
    {
        return $this->belongsTo(GerberFile::class, 'gerber_file_id');
    }

    // Payment Transaction relationship
    public function transaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    // Shipping Address relationship
    public function shippingAddress()
    {
        return $this->belongsTo(UserAddress::class, 'shipping_address_id');
    }

    // Billing Address relationship
    public function billingAddress()
    {
        return $this->belongsTo(UserAddress::class, 'billing_address_id');
    }

    // Source / Parent Order relationship (for Reorders)
    public function sourceOrder()
    {
        return $this->belongsTo(PcbOrder::class, 'source_order_id');
    }

    // Child Reorders created from this order
    public function reorderChildren()
    {
        return $this->hasMany(PcbOrder::class, 'source_order_id');
    }

    // Combo Orders (Child orders under this parent order)
    public function comboOrders()
    {
        $relation = $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_combos',
            'parent_order_id',
            'combo_order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status_id', 'pcb_orders.pn_number');

        if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
            $relation->whereNull('pcb_order_combos.deleted_at');
        }

        return $relation->distinct();
    }

    // Combo Parent Record
    public function comboParentRecord()
    {
        return $this->hasOne(PcbOrderCombo::class, 'combo_order_id');
    }

    // Old Order Numbers (Referenced previous orders for this order)
    public function oldOrders()
    {
        $relation = $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_old_orders',
            'order_id',
            'old_order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status_id', 'pcb_orders.pn_number');

        if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
            $relation->whereNull('pcb_order_old_orders.deleted_at');
        }

        return $relation->distinct();
    }

    // Reverse relationship (Orders that reference this order as their old order)
    public function referencedAsOldOrderBy()
    {
        $relation = $this->belongsToMany(
            PcbOrder::class,
            'pcb_order_old_orders',
            'old_order_id',
            'order_id'
        )->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status_id', 'pcb_orders.pn_number');

        if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
            $relation->whereNull('pcb_order_old_orders.deleted_at');
        }

        return $relation->distinct();
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
        'is_reorder'  => 'boolean',
        'jlcpcb_quotation_snapshot' => 'array',
    ];

    protected $appends = [
        'status_details',
        'customer_name',
        'status',
        'completed_qty',
        'client_id',
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

        static::saving(function ($order) {
            // If statuses table is empty (e.g. test environment without seeders), skip strict validation
            if (\App\Services\OrderStatusResolver::getAllStatuses()->isEmpty()) {
                return;
            }

            // If creating without any status, set canonical default
            if (!$order->exists && empty($order->status_id)) {
                $canonical = \App\Services\OrderStatusResolver::getDefaultStatus();
                if ($canonical) {
                    $order->status_id = $canonical->id;
                    $order->setRelation('statusDetails', $canonical);
                }
                return;
            }

            // If status_id is dirty, validate against canonical status table
            if ($order->isDirty('status_id')) {
                $statusIdVal = $order->status_id;
                if ($statusIdVal === null || trim((string)$statusIdVal) === '') {
                    $canonical = \App\Services\OrderStatusResolver::getDefaultStatus();
                } else {
                    $canonical = \App\Services\OrderStatusResolver::resolve($statusIdVal);
                }

                if (!$canonical) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'status_id' => ["The order status ID '" . trim((string)$statusIdVal) . "' is invalid."],
                    ]);
                }

                $order->status_id = $canonical->id;
                $order->setRelation('statusDetails', $canonical);
            }
        });

        static::updating(function ($order) {
            if ($order->isDirty('status_id')) {
                $oldStatusId = $order->getOriginal('status_id');
                $newStatusId = $order->status_id;

                $oldStatusObj = $oldStatusId ? \App\Services\OrderStatusResolver::resolve($oldStatusId) : null;
                $newStatusObj = $newStatusId ? \App\Services\OrderStatusResolver::resolve($newStatusId) : null;

                $oldStatus = strtolower(trim((string)($oldStatusObj ? $oldStatusObj->name : '')));
                $newStatus = strtolower(trim((string)($newStatusObj ? $newStatusObj->name : '')));

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
            $statusObj = !empty($order->status_id) ? \App\Services\OrderStatusResolver::resolve($order->status_id) : null;
            $status = strtolower(trim((string)($statusObj ? $statusObj->name : '')));
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

    // Meta relationship alias
    public function meta()
    {
        return $this->metas();
    }
}


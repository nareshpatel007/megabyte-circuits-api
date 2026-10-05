<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcbOrderStatusHistory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pcb_order_status_histories';

    public $timestamps = false;

    protected $fillable = [
        'pcb_order_id',
        'status_id',
        'admin_id',
        'status_name',
        'remark',
        'created_at'
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'pcb_order_id');
    }

    public function statusDetails()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function admin()
    {
        return $this->belongsTo(PcbUser::class, 'admin_id');
    }

    public function getStatusNameAttribute($value)
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

        return $value;
    }
}

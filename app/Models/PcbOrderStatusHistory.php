<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbOrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'pcb_order_status_histories';

    public $timestamps = false;

    protected $fillable = [
        'pcb_order_id',
        'admin_id',
        'status_name',
        'remark',
        'created_at'
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'pcb_order_id');
    }

    public function admin()
    {
        return $this->belongsTo(PcbUser::class, 'admin_id');
    }
}

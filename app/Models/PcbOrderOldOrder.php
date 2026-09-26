<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbOrderOldOrder extends Model
{
    use HasFactory;

    protected $table = 'pcb_order_old_orders';

    protected $fillable = [
        'order_id',
        'old_order_id',
    ];

    public function oldOrder()
    {
        return $this->belongsTo(PcbOrder::class, 'old_order_id');
    }

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'order_id');
    }
}

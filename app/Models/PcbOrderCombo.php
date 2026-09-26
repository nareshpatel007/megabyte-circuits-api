<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbOrderCombo extends Model
{
    use HasFactory;

    protected $table = 'pcb_order_combos';

    protected $fillable = [
        'parent_order_id',
        'combo_order_id',
    ];

    public function parentOrder()
    {
        return $this->belongsTo(PcbOrder::class, 'parent_order_id');
    }

    public function comboOrder()
    {
        return $this->belongsTo(PcbOrder::class, 'combo_order_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcbOrderMeta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pcb_order_meta';

    protected $fillable = [
        'pcb_order_id',
        'meta_key',
        'meta_value',
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'pcb_order_id');
    }
}

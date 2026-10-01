<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PcbOrderNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pcb_order_notes';

    protected $fillable = [
        'pcb_order_id',
        'admin_id',
        'note',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'pcb_order_id');
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}

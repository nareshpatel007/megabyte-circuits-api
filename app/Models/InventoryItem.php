<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'inventory_items';

    protected $fillable = [
        'sku',
        'name',
        'unit_price',
        'available_quantity',
        'low_stock_threshold',
        'status',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'available_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
    ];

    public function logs()
    {
        return $this->hasMany(InventoryLog::class, 'inventory_item_id')->orderBy('id', 'desc');
    }
}

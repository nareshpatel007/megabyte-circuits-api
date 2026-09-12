<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeyProductMargin extends Model
{
    use HasFactory;

    protected $table = 'digikey_product_margins';

    protected $fillable = [
        'digikey_product_id',
        'tier_quantity',
        'margin_type',
        'margin_value',
        'is_active',
    ];

    protected $casts = [
        'digikey_product_id' => 'integer',
        'tier_quantity' => 'integer',
        'margin_value' => 'float',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(DigiKeyProduct::class, 'digikey_product_id', 'id');
    }
}

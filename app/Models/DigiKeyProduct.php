<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeyProduct extends Model
{
    use HasFactory;

    protected $table = 'digikey_products';

    protected $fillable = [
        'digikey_product_number',
        'manufacturer_product_number',
        'manufacturer_name',
        'manufacturer_id',
        'product_description',
        'detailed_description',
        'unit_price',
        'product_url',
        'datasheet_url',
        'photo_url',
        'quantity_available',
        'product_status',
        'search_keyword',
        'raw_response',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'quantity_available' => 'integer',
        'raw_response' => 'array',
    ];
}

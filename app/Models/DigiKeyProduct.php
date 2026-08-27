<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeyProduct extends Model
{
    use HasFactory;

    protected $table = 'digikey_products';

    protected $fillable = [
        'category_id',
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
        'product_variations',
        'parameters',
        'classifications',
        'series',
        'other_names',
        'base_product_number',
        'category_details',
        'date_last_buy_chance',
        'shipping_info',
        'back_order_not_allowed',
        'normally_stocking',
        'discontinued',
        'end_of_life',
        'ncnr',
        'primary_video_url',
        'manufacturer_lead_weeks',
        'manufacturer_public_quantity',
        'quantity_available',
        'product_status',
        'search_keyword',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'manufacturer_id' => 'integer',
        'unit_price' => 'float',
        'quantity_available' => 'integer',
        'manufacturer_public_quantity' => 'integer',
        'product_variations' => 'array',
        'parameters' => 'array',
        'classifications' => 'array',
        'series' => 'array',
        'other_names' => 'array',
        'base_product_number' => 'array',
        'category_details' => 'array',
        'shipping_info' => 'array',
        'back_order_not_allowed' => 'boolean',
        'normally_stocking' => 'boolean',
        'discontinued' => 'boolean',
        'end_of_life' => 'boolean',
        'ncnr' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(DigiKeyCategory::class, 'category_id', 'category_id');
    }
}

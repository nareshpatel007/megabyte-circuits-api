<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeyCategory extends Model
{
    use HasFactory;

    protected $table = 'digikey_categories';

    protected $fillable = [
        'category_id',
        'parent_id',
        'name',
        'product_count',
        'raw_response',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'parent_id' => 'integer',
        'product_count' => 'integer',
        'raw_response' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(DigiKeyCategory::class, 'parent_id', 'category_id');
    }

    public function children()
    {
        return $this->hasMany(DigiKeyCategory::class, 'parent_id', 'category_id');
    }

    public function products()
    {
        return $this->hasMany(DigiKeyProduct::class, 'category_id', 'category_id');
    }
}

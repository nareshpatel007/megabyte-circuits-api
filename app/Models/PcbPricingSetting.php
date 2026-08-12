<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcbPricingSetting extends Model
{
    protected $table = 'pcb_pricing_settings';

    protected $fillable = [
        'key',
        'value',
        'description'
    ];

    protected $casts = [
        'value' => 'array'
    ];
}

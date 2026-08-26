<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeyManufacturer extends Model
{
    use HasFactory;

    protected $table = 'digikey_manufacturers';

    protected $fillable = [
        'manufacturer_id',
        'name',
    ];

    protected $casts = [
        'manufacturer_id' => 'integer',
    ];
}

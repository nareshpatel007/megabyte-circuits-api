<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigiKeySyncState extends Model
{
    use HasFactory;

    protected $table = 'digikey_sync_states';

    protected $fillable = [
        'last_cat_index',
        'last_mfg_index',
        'total_synced_products',
    ];

    protected $casts = [
        'last_cat_index' => 'integer',
        'last_mfg_index' => 'integer',
        'total_synced_products' => 'integer',
    ];
}

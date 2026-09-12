<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'holidays';

    protected $fillable = [
        'name',
        'date',
        'description',
        'is_active',
        'created_by'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'created_by' => 'integer'
    ];
}

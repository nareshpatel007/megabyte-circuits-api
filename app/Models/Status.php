<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    use HasFactory;

    protected $table = 'pcb_statuses';

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'color',
        'is_active',
    ];
}

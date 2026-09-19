<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbImportRow extends Model
{
    use HasFactory;

    protected $table = 'pcb_import_rows';

    protected $fillable = [
        'import_id',
        'row_number',
        'status',
        'customer_id',
        'order_id',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function import()
    {
        return $this->belongsTo(PcbImport::class, 'import_id');
    }
}

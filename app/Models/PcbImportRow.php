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
        'row_data',
        'status',
        'validation_status',
        'validation_errors',
        'customer_id',
        'customer_action',
        'resolved_customer_id',
        'is_new_customer',
        'is_duplicate',
        'matched_order_number',
        'order_id',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'row_data' => 'array',
        'validation_errors' => 'array',
        'is_new_customer' => 'boolean',
        'is_duplicate' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function import()
    {
        return $this->belongsTo(PcbImport::class, 'import_id');
    }
}

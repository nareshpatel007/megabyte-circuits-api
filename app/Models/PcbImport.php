<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbImport extends Model
{
    use HasFactory;

    protected $table = 'pcb_imports';

    protected $fillable = [
        'file_name',
        'original_file_name',
        'file_path',
        'file_type',
        'file_size',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'skipped_rows',
        'duplicate_rows',
        'existing_customers',
        'new_customers',
        'duplicate_action',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
        'created_by',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'processed_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'skipped_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'existing_customers' => 'integer',
        'new_customers' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function errors()
    {
        return $this->hasMany(PcbImportError::class, 'import_id');
    }

    public function rows()
    {
        return $this->hasMany(PcbImportRow::class, 'import_id');
    }
}

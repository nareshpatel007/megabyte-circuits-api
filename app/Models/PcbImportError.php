<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbImportError extends Model
{
    use HasFactory;

    protected $table = 'pcb_import_errors';

    protected $fillable = [
        'import_id',
        'row_number',
        'column_name',
        'value',
        'error_message',
    ];

    public function import()
    {
        return $this->belongsTo(PcbImport::class, 'import_id');
    }
}

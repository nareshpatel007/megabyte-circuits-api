<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobCardDocument extends Model
{
    use HasFactory;

    protected $table = 'job_card_documents';

    protected $fillable = [
        'pcb_order_id',
        'original_name',
        'stored_name',
        'file_path',
        'mime_type',
        'file_type',
        'source_type',
        'converted_pdf_path',
        'converted_pdf_name',
        'page_count',
        'file_size',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'page_count' => 'integer',
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(PcbOrder::class, 'pcb_order_id');
    }
}

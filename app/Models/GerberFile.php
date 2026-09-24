<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GerberFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'gerber_files';

    protected $fillable = [
        'user_id',
        'original_name',
        'file_name',
        'file_path',
        'file_url',
        'file_size',
        'board_name',
        'preview_data',
        'analysis_data',
        'jlcpcb_file_key',
        'jlcpcb_upload_status',
        'jlcpcb_uploaded_at',
        'jlcpcb_upload_error',
    ];

    protected $casts = [
        'analysis_data' => 'array',
        'jlcpcb_uploaded_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(PcbUser::class, 'user_id');
    }

    public function orders()
    {
        return $this->hasMany(PcbOrder::class, 'gerber_file_id');
    }
}

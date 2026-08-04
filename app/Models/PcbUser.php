<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PcbUser extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password_hash',
        'company_name',
        'gst_number',
        'address',
        'status',
        'last_login_at',
    ];

    public function orders()
    {
        return $this->hasMany(PcbOrder::class, 'user_id');
    }
}

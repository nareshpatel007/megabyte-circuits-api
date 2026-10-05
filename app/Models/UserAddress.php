<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'user_addresses';

    protected $fillable = [
        'user_id',
        'address_type',
        'customer_type',
        'company_name',
        'first_name',
        'last_name',
        'country',
        'state',
        'city',
        'street_address',
        'building_no',
        'postal_code',
        'mobile',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(PcbUser::class, 'user_id');
    }
}

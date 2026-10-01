<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'user_id',
        'transaction_number',
        'razorpay_payment_id',
        'razorpay_order_id',
        'razorpay_signature',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payload',
        'error_details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(PcbUser::class, 'user_id');
    }

    public function orders()
    {
        return $this->hasMany(PcbOrder::class, 'transaction_id');
    }
}

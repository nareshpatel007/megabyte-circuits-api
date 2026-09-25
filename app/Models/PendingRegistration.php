<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    use HasFactory;

    protected $table = 'pending_registrations';

    protected $fillable = [
        'registration_token_hash',
        'email',
        'username',
        'name',
        'first_name',
        'last_name',
        'password_hash',
        'company_name',
        'country',
        'gst_number',
        'phone',
        'referral_source',
        'invite_token',
        'payload',
        'otp_hash',
        'otp_expires_at',
        'otp_attempts',
        'max_attempts',
        'last_otp_sent_at',
        'verified_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
        'otp_expires_at' => 'datetime',
        'last_otp_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'used_at' => 'datetime',
        'otp_attempts' => 'integer',
        'max_attempts' => 'integer',
    ];
}

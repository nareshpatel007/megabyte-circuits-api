<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'event_name',
        'category',
        'description',
        'client_enabled',
        'admin_enabled',
        'realtime_enabled',
        'toast_enabled',
        'email_enabled',
        'priority',
        'recipient_permission',
    ];

    protected $casts = [
        'client_enabled' => 'boolean',
        'admin_enabled' => 'boolean',
        'realtime_enabled' => 'boolean',
        'toast_enabled' => 'boolean',
        'email_enabled' => 'boolean',
    ];
}

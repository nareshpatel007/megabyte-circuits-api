<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'event_key',
        'category',
        'title',
        'message',
        'icon',
        'theme',
        'action_url',
        'entity_type',
        'entity_id',
        'metadata',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function scopeForRecipient($query, string $type, ?int $id = null)
    {
        return $query->where('recipient_type', $type)
            ->where(function ($q) use ($id) {
                if ($id !== null) {
                    $q->where('recipient_id', $id)->orWhereNull('recipient_id');
                } else {
                    $q->whereNull('recipient_id');
                }
            });
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}

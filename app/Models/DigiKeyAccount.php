<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class DigiKeyAccount extends Model
{
    protected $table = 'digikey_accounts';

    protected $fillable = [
        'account_name',
        'client_id',
        'client_secret',
        'mode',
        'is_active',
        'status',
        'last_used_at',
        'rate_limited_until',
        'error_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'rate_limited_until' => 'datetime',
    ];

    /**
     * Decrypt client_secret attribute.
     */
    public function getDecryptedClientSecretAttribute(): string
    {
        if (empty($this->client_secret)) {
            return '';
        }
        try {
            return Crypt::decryptString($this->client_secret);
        } catch (\Exception $e) {
            // Fallback if value was stored in plain text
            return $this->client_secret;
        }
    }

    /**
     * Encrypt client_secret attribute on set.
     */
    public function setClientSecretAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['client_secret'] = '';
        } else {
            try {
                // Check if already encrypted
                Crypt::decryptString($value);
                $this->attributes['client_secret'] = $value;
            } catch (\Exception $e) {
                $this->attributes['client_secret'] = Crypt::encryptString($value);
            }
        }
    }

    /**
     * Scope to find usable accounts (active, not disabled, and rate limit expired if set).
     */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->where(function ($q) {
                $q->whereNull('rate_limited_until')
                    ->orWhere('rate_limited_until', '<=', now());
            });
    }

    /**
     * Mark account rate limited
     */
    public function markRateLimited(string $reason = 'Daily limit reached'): void
    {
        $this->update([
            'status' => 'rate_limited',
            'rate_limited_until' => now()->addDay(),
            'error_message' => $reason,
        ]);
    }

    /**
     * Mark account error
     */
    public function markError(string $errorMessage): void
    {
        $this->update([
            'status' => 'error',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark account active & update last used timestamp
     */
    public function touchUsed(): void
    {
        $this->update([
            'status' => 'active',
            'last_used_at' => now(),
            'error_message' => null,
        ]);
    }
}

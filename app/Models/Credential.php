<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Credential extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    /**
     * Get decrypted value or null.
     */
    public function getDecryptedValueAttribute()
    {
        if (empty($this->value)) {
            return '';
        }
        try {
            return Crypt::decryptString($this->value);
        } catch (\Exception $e) {
            // Fallback if value was stored in plain text
            return $this->value;
        }
    }

    /**
     * Encrypt and set value.
     */
    public function setEncryptedValue($plainTextValue)
    {
        if ($plainTextValue === null || $plainTextValue === '') {
            $this->value = null;
        } else {
            $this->value = Crypt::encryptString($plainTextValue);
        }
    }

    /**
     * Mask string function: e.g. fdfddf**********dsdsds
     */
    public static function maskValue(?string $val): string
    {
        if (empty($val)) {
            return '';
        }

        $length = strlen($val);
        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        $prefixLen = min(4, (int)floor($length / 4));
        $suffixLen = min(4, (int)floor($length / 4));
        $maskLen = max(6, $length - $prefixLen - $suffixLen);

        $prefix = substr($val, 0, $prefixLen);
        $suffix = substr($val, -$suffixLen);

        return $prefix . str_repeat('*', 10) . $suffix;
    }

    /**
     * Check if a input string is masked format (contains 10 consecutive asterisks).
     */
    public static function isMasked(?string $val): bool
    {
        if (empty($val)) {
            return false;
        }
        return str_contains($val, '**********');
    }
}

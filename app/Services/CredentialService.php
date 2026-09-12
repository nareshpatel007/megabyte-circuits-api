<?php

namespace App\Services;

use App\Models\Credential;
use RuntimeException;

class CredentialService
{
    /**
     * Get a credential by group and key with database priority, falling back to environment variable or default.
     *
     * @param string $group
     * @param string $key
     * @param string|null $envKey
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $group, string $key, ?string $envKey = null, mixed $default = null): mixed
    {
        // 1. Try to retrieve from database
        try {
            $credential = Credential::where('group', $group)
                ->where('key', $key)
                ->first();

            if ($credential) {
                // Check if active column exists or default to active if not present
                $isActive = isset($credential->is_active) ? (bool)$credential->is_active : true;
                $value = $credential->decrypted_value;

                if ($isActive && $value !== null && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // DB connection or query issue - proceed to fallback
        }

        // 2. Fallback to .env / environment variable if envKey is provided
        if ($envKey !== null) {
            $envValue = env($envKey);
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }

        // 3. Return default value
        return $default;
    }

    /**
     * Get a required credential or throw a clear, non-sensitive configuration error.
     *
     * @param string $group
     * @param string $key
     * @param string|null $envKey
     * @param string|null $customErrorMessage
     * @return string
     * @throws RuntimeException
     */
    public static function getOrThrow(string $group, string $key, ?string $envKey = null, ?string $customErrorMessage = null): string
    {
        $value = static::get($group, $key, $envKey);

        if ($value === null || $value === '') {
            $serviceName = ucfirst($group);
            $msg = $customErrorMessage ?? "{$serviceName} credentials are not configured.";
            throw new RuntimeException($msg);
        }

        return (string)$value;
    }
}

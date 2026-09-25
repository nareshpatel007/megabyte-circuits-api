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

        // 1b. Check alias mapping if group is mail/smtp
        $aliasMap = [
            'MAIL_GLOBAL_HOST'         => ['group' => 'smtp', 'key' => 'MAIL_HOST',         'env' => 'MAIL_HOST'],
            'MAIL_HOST'                => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_HOST', 'env' => 'MAIL_GLOBAL_HOST'],
            'MAIL_GLOBAL_PORT'         => ['group' => 'smtp', 'key' => 'MAIL_PORT',         'env' => 'MAIL_PORT'],
            'MAIL_PORT'                => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_PORT', 'env' => 'MAIL_GLOBAL_PORT'],
            'MAIL_GLOBAL_USERNAME'     => ['group' => 'smtp', 'key' => 'MAIL_USERNAME',     'env' => 'MAIL_USERNAME'],
            'MAIL_USERNAME'            => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_USERNAME', 'env' => 'MAIL_GLOBAL_USERNAME'],
            'MAIL_GLOBAL_PASSWORD'     => ['group' => 'smtp', 'key' => 'MAIL_PASSWORD',     'env' => 'MAIL_PASSWORD'],
            'MAIL_PASSWORD'            => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_PASSWORD', 'env' => 'MAIL_GLOBAL_PASSWORD'],
            'MAIL_GLOBAL_ENCRYPTION'   => ['group' => 'smtp', 'key' => 'MAIL_ENCRYPTION',   'env' => 'MAIL_ENCRYPTION'],
            'MAIL_ENCRYPTION'          => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_ENCRYPTION', 'env' => 'MAIL_GLOBAL_ENCRYPTION'],
            'MAIL_GLOBAL_FROM_ADDRESS' => ['group' => 'smtp', 'key' => 'MAIL_FROM_ADDRESS', 'env' => 'MAIL_FROM_ADDRESS'],
            'MAIL_FROM_ADDRESS'        => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_FROM_ADDRESS', 'env' => 'MAIL_GLOBAL_FROM_ADDRESS'],
            'MAIL_GLOBAL_FROM_NAME'    => ['group' => 'smtp', 'key' => 'MAIL_FROM_NAME',    'env' => 'MAIL_FROM_NAME'],
            'MAIL_FROM_NAME'           => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_FROM_NAME', 'env' => 'MAIL_GLOBAL_FROM_NAME'],
            'MAIL_GLOBAL_BCC_ADDRESS'  => ['group' => 'smtp', 'key' => 'MAIL_BCC_ADDRESS',  'env' => 'MAIL_BCC_ADDRESS'],
            'MAIL_BCC_ADDRESS'         => ['group' => 'mail', 'key' => 'MAIL_GLOBAL_BCC_ADDRESS', 'env' => 'MAIL_GLOBAL_BCC_ADDRESS'],
        ];

        if (isset($aliasMap[$key])) {
            try {
                $aliasInfo = $aliasMap[$key];
                $aliasCred = Credential::where('group', $aliasInfo['group'])
                    ->where('key', $aliasInfo['key'])
                    ->first();
                if ($aliasCred) {
                    $isActive = isset($aliasCred->is_active) ? (bool)$aliasCred->is_active : true;
                    $value = $aliasCred->decrypted_value;
                    if ($isActive && $value !== null && $value !== '') {
                        return $value;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 2. Fallback to .env / environment variable if envKey is provided
        if ($envKey !== null) {
            $envValue = env($envKey);
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }

        if (isset($aliasMap[$key]) && !empty($aliasMap[$key]['env'])) {
            $envValue = env($aliasMap[$key]['env']);
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

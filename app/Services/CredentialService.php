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

        // 1b. Fallback DB key aliases for specific groups (e.g., razorpay, digikey)
        if ($group === 'razorpay') {
            $razorpayDbKeys = [];
            if (in_array($key, ['RAZORPAY_KEY_ID', 'key_id', 'RAZORPAY_TEST_KEY_ID', 'RAZORPAY_LIVE_KEY_ID', 'test_key_id', 'live_key_id'])) {
                $razorpayDbKeys = ['RAZORPAY_TEST_KEY_ID', 'RAZORPAY_LIVE_KEY_ID', 'RAZORPAY_KEY_ID', 'test_key_id', 'live_key_id', 'key_id'];
            } elseif (in_array($key, ['RAZORPAY_KEY_SECRET', 'key_secret', 'RAZORPAY_TEST_KEY_SECRET', 'RAZORPAY_LIVE_KEY_SECRET', 'test_key_secret', 'live_key_secret'])) {
                $razorpayDbKeys = ['RAZORPAY_TEST_KEY_SECRET', 'RAZORPAY_LIVE_KEY_SECRET', 'RAZORPAY_KEY_SECRET', 'test_key_secret', 'live_key_secret', 'key_secret'];
            }

            foreach ($razorpayDbKeys as $altKey) {
                if ($altKey === $key) continue;
                try {
                    $altCred = Credential::where('group', 'razorpay')->where('key', $altKey)->first();
                    if ($altCred) {
                        $isActive = isset($altCred->is_active) ? (bool)$altCred->is_active : true;
                        $value = $altCred->decrypted_value;
                        if ($isActive && $value !== null && $value !== '') {
                            return $value;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        if ($group === 'digikey') {
            $digikeyDbKeys = [];
            if (in_array($key, ['DIGIKEY_CLIENT_ID', 'client_id'])) {
                $digikeyDbKeys = ['DIGIKEY_CLIENT_ID', 'client_id'];
            } elseif (in_array($key, ['DIGIKEY_CLIENT_SECRET', 'client_secret'])) {
                $digikeyDbKeys = ['DIGIKEY_CLIENT_SECRET', 'client_secret'];
            }

            foreach ($digikeyDbKeys as $altKey) {
                if ($altKey === $key) continue;
                try {
                    $altCred = Credential::where('group', 'digikey')->where('key', $altKey)->first();
                    if ($altCred) {
                        $isActive = isset($altCred->is_active) ? (bool)$altCred->is_active : true;
                        $value = $altCred->decrypted_value;
                        if ($isActive && $value !== null && $value !== '') {
                            return $value;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        // 1c. Check alias mapping if group is mail/smtp
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

        // Check group service config & environment fallbacks if DB returns nothing
        if ($group === 'razorpay') {
            if (in_array($key, ['RAZORPAY_KEY_ID', 'key_id', 'RAZORPAY_TEST_KEY_ID', 'RAZORPAY_LIVE_KEY_ID', 'test_key_id', 'live_key_id'])) {
                $configVal = config('services.razorpay.key_id')
                    ?: config('services.razorpay.test_key_id')
                    ?: config('services.razorpay.live_key_id')
                    ?: env('RAZORPAY_KEY_ID')
                    ?: env('RAZORPAY_TEST_KEY_ID')
                    ?: env('RAZORPAY_LIVE_KEY_ID');
                if ($configVal !== null && $configVal !== '') {
                    return $configVal;
                }
            } elseif (in_array($key, ['RAZORPAY_KEY_SECRET', 'key_secret', 'RAZORPAY_TEST_KEY_SECRET', 'RAZORPAY_LIVE_KEY_SECRET', 'test_key_secret', 'live_key_secret'])) {
                $configVal = config('services.razorpay.key_secret')
                    ?: config('services.razorpay.test_key_secret')
                    ?: config('services.razorpay.live_key_secret')
                    ?: env('RAZORPAY_KEY_SECRET')
                    ?: env('RAZORPAY_TEST_KEY_SECRET')
                    ?: env('RAZORPAY_LIVE_KEY_SECRET');
                if ($configVal !== null && $configVal !== '') {
                    return $configVal;
                }
            }
        }

        if ($group === 'digikey') {
            if (in_array($key, ['DIGIKEY_CLIENT_ID', 'client_id'])) {
                $configVal = config('services.digikey.client_id', env('DIGIKEY_CLIENT_ID'));
                if ($configVal !== null && $configVal !== '') {
                    return $configVal;
                }
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

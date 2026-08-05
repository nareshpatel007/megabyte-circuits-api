<?php

namespace App;

use Illuminate\Http\Request;

class CommonHelper
{
    // Generate token
    public static function generate_token()
    {
        $token = uniqid("", TRUE);
        $token = str_replace(".", "-", $token);
        return $token . "-" . rand(10000000, 99999999);
    }

    // Generate random string
    public static function generate_random_string($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString . time();
    }

    // Encrypt function
    public static function encrypt_string($text)
    {
        $key = 'D3CyEDN78M1nG4gIURW96xZal';
        $cipher = 'AES-256-CBC';

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
        $ciphertext = openssl_encrypt($text, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        return bin2hex($iv . $ciphertext); // output: 0-9 + a-f only
    }

    public static function logActivity($userId, $action, $description = null, $request = null, $credits = null)
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('activity_logs')) {
                $ip = ($request instanceof \Illuminate\Http\Request) ? $request->ip() : request()->ip();
                $userAgent = ($request instanceof \Illuminate\Http\Request) ? $request->userAgent() : request()->userAgent();
                \Illuminate\Support\Facades\DB::table('activity_logs')->insert([
                    'user_id' => $userId,
                    'action' => $action,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'log_data' => json_encode([
                        'description' => $description,
                        'credits' => $credits
                    ]),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        } catch (\Throwable $e) {
            // Ignore activity log failure if table is missing
        }
    }

    // Use credits directly for user
    public static function useCredits($userId, $amount, $description = null)
    {
        $user = \Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->first();
        if ($user) {
            $newCredits = max(0, ($user->available_credits ?? 0) - $amount);
            \Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->update([
                'available_credits' => $newCredits,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Log credit usage activity
            \Illuminate\Support\Facades\DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => 'credit_usage',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'log_data' => json_encode([
                    'credits_used' => -$amount,
                    'description' => $description ?: "Used {$amount} credits."
                ]),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
}

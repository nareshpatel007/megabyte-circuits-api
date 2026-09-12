<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Credential;
use Illuminate\Support\Facades\Crypt;

class MigrateCredentialsSeeder extends Seeder
{
    /**
     * Run the database seeds to import env credentials into DB in encrypted format.
     */
    public function run(): void
    {
        $credentialsMap = [
            'razorpay' => [
                'RAZORPAY_TEST_KEY_ID' => env('RAZORPAY_TEST_KEY_ID', ''),
                'RAZORPAY_TEST_KEY_SECRET' => env('RAZORPAY_TEST_KEY_SECRET', ''),
                'RAZORPAY_LIVE_KEY_ID' => env('RAZORPAY_LIVE_KEY_ID', ''),
                'RAZORPAY_LIVE_KEY_SECRET' => env('RAZORPAY_LIVE_KEY_SECRET', ''),
                'RAZORPAY_WEBHOOK_SECRET' => env('RAZORPAY_WEBHOOK_SECRET', ''),
                'RAZORPAY_WEBHOOK_URL' => env('RAZORPAY_WEBHOOK_URL', 'https://api.pcbmfg.in/webhooks/razorpay'),
            ],
            'jlcpcb' => [
                'JLCPCB_APP_ID' => env('JLCPCB_APP_ID', ''),
                'JLCPCB_ACCESS_KEY' => env('JLCPCB_ACCESS_KEY', ''),
                'JLCPCB_BASE_URL' => env('JLCPCB_BASE_URL', 'https://open.jlcpcb.com'),
            ],
            'smtp' => [
                'MAIL_HOST' => env('MAIL_HOST', 'smtp.gmail.com'),
                'MAIL_PORT' => env('MAIL_PORT', '587'),
                'MAIL_USERNAME' => env('MAIL_USERNAME', ''),
                'MAIL_PASSWORD' => env('MAIL_PASSWORD', ''),
                'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS', 'quote@megabytecircuit.com'),
                'MAIL_FROM_NAME' => env('MAIL_FROM_NAME', 'megabytecircuit.com'),
            ],
            'stripe' => [
                'STRIPE_PUBLISHABLE_KEY' => env('STRIPE_PUBLISHABLE_KEY', ''),
                'STRIPE_SECRET_KEY' => env('STRIPE_SECRET_KEY', ''),
                'STRIPE_WEBHOOK_SECRET' => env('STRIPE_WEBHOOK_SECRET', ''),
                'STRIPE_WEBHOOK_ENDPOINT' => env('STRIPE_WEBHOOK_ENDPOINT', 'https://api.pcbmfg.in/webhooks/stripe'),
            ],
            'digikey' => [
                'DIGIKEY_CLIENT_ID' => env('DIGIKEY_CLIENT_ID', ''),
                'DIGIKEY_CLIENT_SECRET' => env('DIGIKEY_CLIENT_SECRET', ''),
                'DIGIKEY_MODE' => env('DIGIKEY_MODE', 'live'),
            ],
            'google' => [
                'GOOGLE_CLIENT_ID' => env('GOOGLE_CLIENT_ID', ''),
                'GOOGLE_CLIENT_SECRET' => env('GOOGLE_CLIENT_SECRET', ''),
            ],
            'imagekit' => [
                'IMAGEKIT_PUBLIC_KEY' => env('IMAGEKIT_PUBLIC_KEY', ''),
                'IMAGEKIT_PRIVATE_KEY' => env('IMAGEKIT_PRIVATE_KEY', ''),
                'IMAGEKIT_URL_ENDPOINT' => env('IMAGEKIT_URL_ENDPOINT', 'https://ik.imagekit.io/8xe0dth2o'),
            ]
        ];

        foreach ($credentialsMap as $group => $items) {
            foreach ($items as $key => $val) {
                $valStr = (string)$val;
                $cred = Credential::firstOrNew(['key' => $key]);
                $cred->group = $group;
                if ($valStr !== '') {
                    $cred->setEncryptedValue($valStr);
                }
                $cred->save();
            }
        }
    }
}

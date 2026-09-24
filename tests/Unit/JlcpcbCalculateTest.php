<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\JlcpcbService;

class JlcpcbCalculateTest extends TestCase
{
    public function test_calculate_signature_generation()
    {
        $service = new JlcpcbService();

        $method = 'POST';
        $urlPath = '/overseas/openapi/pcb/calculate';
        $appId = 'test_app_id';
        $accessKey = 'test_access_key';
        $secretKey = 'test_secret_key_12345';

        $payload = [
            'orderType' => 1,
            'pcbParam' => [
                'layer' => 2,
                'length' => 100,
                'width' => 100,
                'qty' => 5
            ],
            'achieveDate' => 48,
            'fileKey' => 'test_file_key_123'
        ];

        $calcBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $authData = $service->generateJopAuthorization(
            $method,
            $urlPath,
            $calcBody,
            $appId,
            $accessKey,
            $secretKey
        );

        $this->assertEquals('test_app_id', $authData['app_id']);
        $this->assertEquals('test_access_key', $authData['access_key']);
        $this->assertEquals(32, strlen($authData['nonce']));

        // Verify string to sign formatting (line 5 is exact JSON payload)
        $expectedStringToSign = "POST\n/overseas/openapi/pcb/calculate\n" . $authData['timestamp'] . "\n" . $authData['nonce'] . "\n" . $calcBody . "\n";
        $this->assertEquals($expectedStringToSign, $authData['string_to_sign']);

        // Verify HMAC SHA256 Base64 calculation
        $expectedSignature = base64_encode(hash_hmac('sha256', $expectedStringToSign, $secretKey, true));
        $this->assertEquals($expectedSignature, $authData['signature']);
    }
}

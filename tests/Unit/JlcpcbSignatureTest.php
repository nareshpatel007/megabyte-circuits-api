<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\JlcpcbService;

class JlcpcbSignatureTest extends TestCase
{
    public function test_jop_authorization_header_generation()
    {
        $service = new JlcpcbService();

        $method = 'POST';
        $urlPath = '/overseas/openapi/pcb/uploadGerber';
        $metaJson = '{"fileName":"test_gerber.zip"}';
        $appId = 'test_app_id';
        $accessKey = 'test_access_key';
        $secretKey = 'test_secret_key_12345';

        $authData = $service->generateJopAuthorization(
            $method,
            $urlPath,
            $metaJson,
            $appId,
            $accessKey,
            $secretKey
        );

        $this->assertEquals('test_app_id', $authData['app_id']);
        $this->assertEquals('test_access_key', $authData['access_key']);
        $this->assertEquals(32, strlen($authData['nonce']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $authData['nonce']);

        // Verify string to sign formatting (5 lines, each ending with \n)
        $expectedStringToSign = "POST\n/overseas/openapi/pcb/uploadGerber\n" . $authData['timestamp'] . "\n" . $authData['nonce'] . "\n" . '{"fileName":"test_gerber.zip"}' . "\n";
        $this->assertEquals($expectedStringToSign, $authData['string_to_sign']);

        // Verify HMAC SHA256 Base64 calculation
        $expectedSignature = base64_encode(hash_hmac('sha256', $expectedStringToSign, $secretKey, true));
        $this->assertEquals($expectedSignature, $authData['signature']);

        // Verify Authorization header structure
        $expectedHeader = 'JOP appid="test_app_id",accesskey="test_access_key",nonce="' . $authData['nonce'] . '",timestamp="' . $authData['timestamp'] . '",signature="' . $expectedSignature . '"';
        $this->assertEquals($expectedHeader, $authData['authorization']);
    }
}

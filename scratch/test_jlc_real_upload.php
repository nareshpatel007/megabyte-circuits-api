<?php

/**
 * JLCPCB Real Endpoint Signature Variation Tester
 */

$appId = "597733257194213377";
$accessKey = "e37cf942e8a84fbfbfa4e63910aaa9b1";
$secretKey = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";
$baseUrl = "https://open.jlcpcb.com";

// Create a dummy valid zip file for testing
$testZipPath = __DIR__ . '/test_gerber.zip';
if (!file_exists($testZipPath)) {
    $zip = new ZipArchive();
    if ($zip->open($testZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFromString('board.gbr', 'G04 Test Gerber file content*');
        $zip->close();
    }
}

function generateNonce(int $length = 32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

function testUploadVariation($name, $endpoint, $method, $urlPath, $metaJson, $postFields, $appId, $accessKey, $secretKey, $headerFormat = 'jop_lowercase')
{
    $timestamp = time();
    $nonce = generateNonce(32);

    $stringToSign = $method . "\n"
        . $urlPath . "\n"
        . $timestamp . "\n"
        . $nonce . "\n"
        . $metaJson . "\n";

    $hash = hash_hmac('sha256', $stringToSign, $secretKey, true);
    $signature = base64_encode($hash);

    if ($headerFormat === 'jop_lowercase') {
        $auth = 'JOP '
            . 'appid="' . $appId . '",'
            . 'accesskey="' . $accessKey . '",'
            . 'nonce="' . $nonce . '",' .
            'timestamp="' . $timestamp . '",' .
            'signature="' . $signature . '"';
    } elseif ($headerFormat === 'jop_camel') {
        $auth = 'JOP '
            . 'appId="' . $appId . '",'
            . 'accessKey="' . $accessKey . '",'
            . 'nonce="' . $nonce . '",' .
            'timestamp="' . $timestamp . '",' .
            'signature="' . $signature . '"';
    } elseif ($headerFormat === 'bearer') {
        $auth = 'Bearer ' . $accessKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => ($method === 'POST'),
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    $code = $decoded['code'] ?? null;
    $msg = $decoded['message'] ?? $response;

    echo "VARIATION [{$name}]: HTTP {$httpCode} | Code: {$code} | Msg: {$msg}\n";
    if ($code === 200 || $httpCode === 200) {
        echo "   ===> SUCCESS! <===\n";
        echo "   Response: " . print_r($decoded, true) . "\n";
        return true;
    }
    return false;
}

$curlFile = new CURLFile($testZipPath, 'application/zip', 'test_gerber.zip');

echo "Starting JLCPCB Real Signature Variations Test...\n\n";

// Test Matrix:
// 1. Meta JSON = "{}" with fileName in post body
testUploadVariation('1. metaJson={}, postBody=[fileName, file]', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey);

// 2. Meta JSON = "{\"fileName\":\"test_gerber.zip\"}" with fileName in post body
testUploadVariation('2. metaJson={"fileName":"test_gerber.zip"}, postBody=[fileName, file]', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{"fileName":"test_gerber.zip"}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey);

// 3. Meta JSON = "{}" with ONLY file in post body
testUploadVariation('3. metaJson={}, postBody=[file only]', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{}', ['file' => $curlFile], $appId, $accessKey, $secretKey);

// 4. Meta JSON = "" (empty string) with file only
testUploadVariation('4. metaJson="", postBody=[file only]', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '', ['file' => $curlFile], $appId, $accessKey, $secretKey);

// 5. Short path /pcb/uploadGerber for signing
testUploadVariation('5. Path /pcb/uploadGerber, metaJson={}', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/pcb/uploadGerber', '{}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey);
testUploadVariation('6. Path /pcb/uploadGerber, metaJson={"fileName":"test_gerber.zip"}', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/pcb/uploadGerber', '{"fileName":"test_gerber.zip"}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey);

// 7. Header Format camelCase (appId, accessKey)
testUploadVariation('7. CamelCase Header (appId, accessKey), metaJson={}', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey, 'jop_camel');
testUploadVariation('8. CamelCase Header (appId, accessKey), metaJson={"fileName":"test_gerber.zip"}', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{"fileName":"test_gerber.zip"}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey, 'jop_camel');

// 8. Bearer Auth token instead of JOP
testUploadVariation('9. Bearer Token Auth', "{$baseUrl}/overseas/openapi/pcb/uploadGerber", 'POST', '/overseas/openapi/pcb/uploadGerber', '{}', ['fileName' => 'test_gerber.zip', 'file' => $curlFile], $appId, $accessKey, $secretKey, 'bearer');

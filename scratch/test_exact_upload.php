<?php

/**
 * Test Exact Winning Signature Format
 */

$appId = "597733257194213377";
$accessKey = "e37cf942e8a84fbfbfa4e63910aaa9b1";
$secretKey = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";
$baseUrl = "https://open.jlcpcb.com";
$endpoint = "{$baseUrl}/overseas/openapi/pcb/uploadGerber";
$urlPath = "/overseas/openapi/pcb/uploadGerber";

$testZipPath = __DIR__ . '/test_gerber.zip';
$originalName = "CAM for AA2121A.ZIP";

function generateNonce(int $length = 32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

function testExact($label, $metaJson, $postFields)
{
    global $endpoint, $urlPath, $appId, $accessKey, $secretKey;

    $timestamp = time();
    $nonce = generateNonce(32);

    $stringToSign = "POST\n" . $urlPath . "\n" . $timestamp . "\n" . $nonce . "\n" . $metaJson . "\n";
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $secretKey, true));

    $auth = 'JOP '
        . 'appid="' . $appId . '",'
        . 'accesskey="' . $accessKey . '",'
        . 'nonce="' . $nonce . '",'
        . 'timestamp="' . $timestamp . '",'
        . 'signature="' . $signature . '"';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($res, true);
    echo "TEST [{$label}]: HTTP {$httpCode} | Response: {$res}\n";
    return $decoded;
}

$curlFile = new CURLFile($testZipPath, 'application/zip', $originalName);

echo "=== TESTING WINNING SIGNATURE FORMATS ===\n";

// A: metaJson = "" and postFields = [file only]
testExact('A: metaJson="", file only', '', ['file' => $curlFile]);

// B: metaJson = "" and postFields = [fileName, file]
testExact('B: metaJson="", fileName + file', '', ['fileName' => $originalName, 'file' => $curlFile]);

// C: metaJson = "{}" and postFields = [file only]
testExact('C: metaJson="{}", file only', '{}', ['file' => $curlFile]);

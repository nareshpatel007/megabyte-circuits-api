<?php

$appId = "597733257194213377";
$accessKey = "e37cf942e8a84fbfbfa4e63910aaa9b1";
$correctSecretKey = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";
$wrongSecretKey = "WRONG_SECRET_KEY_1234567890000000";
$baseUrl = "https://open.jlcpcb.com";
$endpoint = "{$baseUrl}/overseas/openapi/pcb/uploadGerber";
$urlPath = "/overseas/openapi/pcb/uploadGerber";

$testZipPath = __DIR__ . '/test_gerber.zip';
$curlFile = new CURLFile($testZipPath, 'application/zip', 'test_gerber.zip');

function testKey($label, $secKey, $metaJson) {
    global $endpoint, $urlPath, $appId, $accessKey, $curlFile;
    $timestamp = time();
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $nonce = '';
    for ($i = 0; $i < 32; $i++) {
        $nonce .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $stringToSign = "POST\n" . $urlPath . "\n" . $timestamp . "\n" . $nonce . "\n" . $metaJson . "\n";
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $secKey, true));
    $auth = 'JOP appid="' . $appId . '",accesskey="' . $accessKey . '",nonce="' . $nonce . '",timestamp="' . $timestamp . '",signature="' . $signature . '"';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['fileName' => 'test_gerber.zip', 'file' => $curlFile],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "RESULT [{$label}]: HTTP {$code} | Response: {$res}\n";
}

echo "=== TESTING WITH CORRECT SECRET KEY ===\n";
testKey("Correct Secret Key with metaJson={}", $correctSecretKey, "{}");
testKey("Correct Secret Key with metaJson=fileName JSON", $correctSecretKey, '{"fileName":"test_gerber.zip"}');

echo "\n=== TESTING WITH WRONG SECRET KEY ===\n";
testKey("Wrong Secret Key with metaJson={}", $wrongSecretKey, "{}");
testKey("Wrong Secret Key with metaJson=fileName JSON", $wrongSecretKey, '{"fileName":"test_gerber.zip"}');

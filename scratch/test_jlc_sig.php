<?php

/**
 * Isolated JLCPCB Signature & Upload Debugger
 */

function buildStringToSign(string $method, string $path, int $timestamp, string $nonce, string $body): string
{
    return $method . "\n"
        . $path . "\n"
        . $timestamp . "\n"
        . $nonce . "\n"
        . $body . "\n";
}

function generateSignature(string $stringToSign, string $secretKey): string
{
    return base64_encode(hash_hmac('sha256', $stringToSign, $secretKey, true));
}

function buildAuthorizationHeader(string $appId, string $accessKey, string $nonce, int $timestamp, string $signature): string
{
    return 'JOP '
        . 'appid="' . $appId . '",'
        . 'accesskey="' . $accessKey . '",'
        . 'nonce="' . $nonce . '",'
        . 'timestamp="' . $timestamp . '",'
        . 'signature="' . $signature . '"';
}

// Test Sample from JLCPCB Documentation Example:
// Method: POST
// Path: /order/v1/createOrder
// Timestamp: 1625208260
// Nonce: IZHEJYNIHYZIE8S0LLC0VWTPJVRRTO50
// Body: {"goodsId":100,"quantity":52,"createdTime":"2024-03-21 10:03:20"}

$sampleMethod = "POST";
$samplePath = "/order/v1/createOrder";
$sampleTimestamp = 1625208260;
$sampleNonce = "IZHEJYNIHYZIE8S0LLC0VWTPJVRRTO50";
$sampleBody = '{"goodsId":100,"quantity":52,"createdTime":"2024-03-21 10:03:20"}';
$sampleSecret = "dummy_secret_key_123";

$sampleStringToSign = buildStringToSign($sampleMethod, $samplePath, $sampleTimestamp, $sampleNonce, $sampleBody);
$sampleSig = generateSignature($sampleStringToSign, $sampleSecret);
$sampleAuth = buildAuthorizationHeader("dummy_app", "dummy_access", $sampleNonce, $sampleTimestamp, $sampleSig);

echo "--- TEST SAMPLE STRING TO SIGN (ESCAPED) ---\n";
echo addcslashes($sampleStringToSign, "\n\r\t") . "\n\n";

echo "--- TEST SAMPLE SIGNATURE (Base64) ---\n";
echo $sampleSig . "\n\n";

echo "--- TEST SAMPLE AUTHORIZATION HEADER ---\n";
echo $sampleAuth . "\n\n";

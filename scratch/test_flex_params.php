<?php

$appId = "597733257194213377";
$accessKey = "e37cf942e8a84fbfbfa4e63910aaa9b1";
$secretKey = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";
$baseUrl = "https://open.jlcpcb.com";

function generateNonce(int $length = 32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

function callCalc($payload, $appId, $accessKey, $secretKey, $baseUrl)
{
    $calcEndpoint = "{$baseUrl}/overseas/openapi/pcb/calculate";
    $calcPath = "/overseas/openapi/pcb/calculate";

    $calcBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $calcTimestamp = time();
    $calcNonce = generateNonce(32);
    $calcStringToSign = "POST\n" . $calcPath . "\n" . $calcTimestamp . "\n" . $calcNonce . "\n" . $calcBody . "\n";
    $calcSig = base64_encode(hash_hmac('sha256', $calcStringToSign, $secretKey, true));
    $calcAuth = 'JOP appid="' . $appId . '",accesskey="' . $accessKey . '",nonce="' . $calcNonce . '",timestamp="' . $calcTimestamp . '",signature="' . $calcSig . '"';

    $ch = curl_init($calcEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $calcBody,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $calcAuth,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

// 1. Test exact payload that failed
$basePayload = [
    'orderType' => 1,
    'country' => 'IN',
    'achieveDate' => 48,
    'fileKey' => '',
    'pcbParam' => [
        'layer' => 2,
        'width' => 100,
        'length' => 100,
        'qty' => 5,
        'thickness' => 0.11,
        'pcbColor' => 0,
        'surfaceFinish' => 2,
        'copperWeight' => 1,
        'differentDesign' => 1,
        'flyingProbeTest' => 2,
        'markOnPcb' => 1,
        'materialDetails' => 0,
        'minHole' => 0.3,
        'needTechnics' => 0,
        'panelFlag' => 0,
        'plateType' => 8,
        'autoConfirmProductionFile' => true,
        'viaCovering' => 1,
    ]
];

echo "Testing failed payload (plateType 8, thickness 0.11)...\n";
list($c, $r) = callCalc($basePayload, $appId, $accessKey, $secretKey, $baseUrl);
echo "Result ($c): $r\n\n";

// Test plateType values: 1 to 10
for ($pt = 1; $pt <= 10; $pt++) {
    $p = $basePayload;
    $p['pcbParam']['plateType'] = $pt;
    // For standard tests, try thickness 1.6 or 0.11
    list($c, $r) = callCalc($p, $appId, $accessKey, $secretKey, $baseUrl);
    echo "plateType=$pt with thickness=0.11: ($c) $r\n";
}

// Test orderType: what if Flex is an orderType (e.g. orderType 4 or plateType 1 with materialDetails or something)?
for ($ot = 1; $ot <= 6; $ot++) {
    $p = $basePayload;
    $p['orderType'] = $ot;
    $p['pcbParam']['plateType'] = 1;
    $p['pcbParam']['thickness'] = 1.6;
    list($c, $r) = callCalc($p, $appId, $accessKey, $secretKey, $baseUrl);
    echo "orderType=$ot, plateType=1: ($c) $r\n";
}

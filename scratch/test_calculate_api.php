<?php

/**
 * Test Gerber Upload + Online Quotation Calculate API Flow (Corrected Parameters)
 */

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

// STEP 1: Upload Gerber File
echo "--- STEP 1: UPLOADING GERBER FILE ---\n";
$uploadEndpoint = "{$baseUrl}/overseas/openapi/pcb/uploadGerber";
$uploadPath = "/overseas/openapi/pcb/uploadGerber";

$testZipPath = __DIR__ . '/test_gerber.zip';
$curlFile = new CURLFile($testZipPath, 'application/zip', 'CAM_AA2121A.zip');

$timestamp = time();
$nonce = generateNonce(32);
$uploadStringToSign = "POST\n" . $uploadPath . "\n" . $timestamp . "\n" . $nonce . "\n\n";
$uploadSig = base64_encode(hash_hmac('sha256', $uploadStringToSign, $secretKey, true));
$uploadAuth = 'JOP appid="' . $appId . '",accesskey="' . $accessKey . '",nonce="' . $nonce . '",timestamp="' . $timestamp . '",signature="' . $uploadSig . '"';

$ch = curl_init($uploadEndpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['fileName' => 'CAM_AA2121A.zip', 'file' => $curlFile],
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $uploadAuth,
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
]);

$uploadRes = curl_exec($ch);
$uploadHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Upload Response (HTTP {$uploadHttpCode}): {$uploadRes}\n\n";

$uploadDecoded = json_decode($uploadRes, true);
$fileKey = $uploadDecoded['data'] ?? null;

if (!$fileKey || !is_string($fileKey)) {
    echo "ERROR: Could not obtain fileKey from upload.\n";
    exit(1);
}

echo "Obtained fileKey: {$fileKey}\n\n";

// STEP 2: Calculate PCB Quotation
echo "--- STEP 2: CALCULATING ONLINE QUOTATION ---\n";
$calcEndpoint = "{$baseUrl}/overseas/openapi/pcb/calculate";
$calcPath = "/overseas/openapi/pcb/calculate";

$payload = [
    'orderType' => 1,
    'pcbParam' => [
        'layer' => 2,
        'length' => 100,
        'width' => 100,
        'qty' => 5,
        'thickness' => 1.6,
        'pcbColor' => 0,
        'surfaceFinish' => 0,
        'copperWeight' => 1,
        'goldFinger' => 0,
        'materialDetails' => 0,
        'panelFlag' => 0,
        'panelByJLCPCB_X' => 0,
        'panelByJLCPCB_Y' => 0,
        'differentDesign' => 1,
        'flyingProbeTest' => 1,
        'castellatedHoles' => 0,
        'orderDetailsRemark' => 'Quote calculated via test script',
        'cascadeStructure' => 0,
        'impedanceFlag' => 'no',
        'isAddCustomerCode' => 'nocode',
        'plateType' => 1,
        'autoConfirmProductionFile' => true,
        'markOnPcb' => 1,
        'viaCovering' => 1,
        'needTechnics' => 0
    ],
    'achieveDate' => 48,
    'fileKey' => $fileKey
];

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

$calcRes = curl_exec($ch);
$calcHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Calculate Response (HTTP {$calcHttpCode}): {$calcRes}\n\n";

$calcDecoded = json_decode($calcRes, true);
echo "Decoded Quotation Output:\n";
print_r($calcDecoded);

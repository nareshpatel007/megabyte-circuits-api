<?php

/**
 * JLCPCB Permutation Tester for uploadGerber
 */

$appId = "597733257194213377";
$accessKey = "e37cf942e8a84fbfbfa4e63910aaa9b1";
$secretKey = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";
$baseUrl = "https://open.jlcpcb.com";
$endpoint = "{$baseUrl}/overseas/openapi/pcb/uploadGerber";
$path = "/overseas/openapi/pcb/uploadGerber";

$fileName = "CAM for AA2121A.ZIP";
$testZipPath = __DIR__ . '/test_gerber.zip';
$curlFile = new CURLFile($testZipPath, 'application/zip', $fileName);

function runPermutation($id, $signKey, $metaJsonToSign, $postFieldsToSend, $pathForSigning = '/overseas/openapi/pcb/uploadGerber', $headerStyle = 'jop_lowercase') {
    global $endpoint, $appId, $accessKey, $secretKey;

    $timestamp = time();
    $nonce = generateNonce(32);

    $stringToSign = "POST\n" . $pathForSigning . "\n" . $timestamp . "\n" . $nonce . "\n" . $metaJsonToSign . "\n";
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $signKey, true));

    if ($headerStyle === 'jop_lowercase') {
        $auth = 'JOP appid="' . $appId . '",accesskey="' . $accessKey . '",nonce="' . $nonce . '",timestamp="' . $timestamp . '",signature="' . $signature . '"';
    } else {
        $auth = 'JOP appId="' . $appId . '",accessKey="' . $accessKey . '",nonce="' . $nonce . '",timestamp="' . $timestamp . '",signature="' . $signature . '"';
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFieldsToSend,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);

    $res = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($res, true);
    $code = $decoded['code'] ?? null;
    $msg = $decoded['message'] ?? $res;

    echo sprintf("[%02d] HTTP %d | Code: %-4s | Msg: %s\n", $id, $httpCode, (string)$code, $msg);
    if ($code !== 401 && $code !== 400 && $httpCode !== 401) {
        echo "     *** NOT 401! Details: " . print_r($decoded, true) . "\n";
    }
}

function generateNonce(int $length = 32): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $res = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $res;
}

echo "Testing Signature Permutations against JLCPCB...\n";

// Permutation 1: Sign {}, Send [fileName, file]
runPermutation(1, $secretKey, '{}', ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 2: Sign {"fileName":"CAM for AA2121A.ZIP"}, Send [fileName, file]
runPermutation(2, $secretKey, json_encode(['fileName' => $fileName]), ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 3: Sign {"fileName": "CAM for AA2121A.ZIP"} (with space), Send [fileName, file]
runPermutation(3, $secretKey, '{"fileName": "CAM for AA2121A.ZIP"}', ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 4: Sign {}, Send [file only]
runPermutation(4, $secretKey, '{}', ['file' => $curlFile]);

// Permutation 5: Sign "", Send [file only]
runPermutation(5, $secretKey, '', ['file' => $curlFile]);

// Permutation 6: Sign URL-encoded filename in JSON, Send [fileName, file]
runPermutation(6, $secretKey, json_encode(['fileName' => rawurlencode($fileName)]), ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 7: Sign using accessKey instead of secretKey, Sign {}
runPermutation(7, $accessKey, '{}', ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 8: Sign using accessKey instead of secretKey, Sign {"fileName":"..."}
runPermutation(8, $accessKey, json_encode(['fileName' => $fileName]), ['fileName' => $fileName, 'file' => $curlFile]);

// Permutation 9: Path /pcb/uploadGerber, Sign {}
runPermutation(9, $secretKey, '{}', ['fileName' => $fileName, 'file' => $curlFile], '/pcb/uploadGerber');

// Permutation 10: Path /pcb/uploadGerber, Sign {"fileName":"..."}
runPermutation(10, $secretKey, json_encode(['fileName' => $fileName]), ['fileName' => $fileName, 'file' => $curlFile], '/pcb/uploadGerber');

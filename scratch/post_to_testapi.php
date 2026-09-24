<?php

$ch = curl_init('http://localhost/megabyte-circuits/megabyte-circuits-api/public/testapi.php');

$testZipPath = __DIR__ . '/test_gerber.zip';
$curlFile = new CURLFile($testZipPath, 'application/zip', 'CAM for AA2121A.ZIP');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'app_id' => '597733257194213377',
        'access_key' => 'e37cf942e8a84fbfbfa4e63910aaa9b1',
        'secret_key' => '5tnweyehERnKK7CFWkkyBHqddtcRnENB',
        'meta_json_option' => 'auto',
        'gerber_file' => $curlFile
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$res = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response from local testapi.php (HTTP {$httpCode}):\n";
if (preg_match('/<div class="(?:success|error)">(.*?)<\/div>/s', $res, $m)) {
    echo "Result Alert: " . trim(strip_tags($m[1])) . "\n";
}
if (preg_match('/<h3>JSON<\/h3>\s*<pre>(.*?)<\/pre>/s', $res, $m)) {
    echo "Response JSON: " . trim($m[1]) . "\n";
}

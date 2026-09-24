<?php

$ch = curl_init('http://localhost/megabyte-circuits/megabyte-circuits-api/public/testapi.php');

$testZipPath = __DIR__ . '/test_gerber.zip';
$curlFile = new CURLFile($testZipPath, 'application/zip', 'CAM_AA2121A.zip');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'app_id' => '597733257194213377',
        'access_key' => 'e37cf942e8a84fbfbfa4e63910aaa9b1',
        'secret_key' => '5tnweyehERnKK7CFWkkyBHqddtcRnENB',
        'layer' => '2',
        'length' => '100',
        'width' => '100',
        'qty' => '5',
        'thickness' => '1.6',
        'pcb_color' => '0',
        'surface_finish' => '0',
        'copper_weight' => '1',
        'achieve_date' => '48',
        'meta_json_option' => 'empty_string',
        'gerber_file' => $curlFile
    ],
    CURLOPT_RETURNTRANSFER => true
]);

$res = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n\n";

if (preg_match('/<h2>Step 1: Gerber Upload Result.*?<\/h2>\s*<div class="(?:success|error)">(.*?)<\/div>/s', $res, $m)) {
    echo "=== STEP 1 (UPLOAD) ===\n" . trim(strip_tags($m[1])) . "\n\n";
}

if (preg_match('/<h2>Step 2: Online PCB Quotation Result.*?<\/h2>\s*<div class="(?:success|error)">(.*?)<\/div>/s', $res, $m)) {
    echo "=== STEP 2 (QUOTATION) ===\n" . trim(strip_tags($m[1])) . "\n\n";
}

if (preg_match('/<span class="price-badge">(.*?)<\/span>/s', $res, $m)) {
    echo "PRICE BADGE: " . trim(strip_tags($m[1])) . "\n";
}

if (preg_match('/<h3>Quotation Response Raw JSON<\/h3>\s*<pre>(.*?)<\/pre>/s', $res, $m)) {
    echo "QUOTATION RAW JSON:\n" . html_entity_decode(trim($m[1])) . "\n";
}

<?php

function testMode($mode) {
    $ch = curl_init('http://localhost/megabyte-circuits/megabyte-circuits-api/public/testapi.php');

    $testZipPath = __DIR__ . '/test_gerber.zip';
    $curlFile = new CURLFile($testZipPath, 'application/zip', 'CAM for AA2121A.ZIP');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'app_id' => '597733257194213377',
            'access_key' => 'e37cf942e8a84fbfbfa4e63910aaa9b1',
            'secret_key' => '5tnweyehERnKK7CFWkkyBHqddtcRnENB',
            'meta_json_option' => $mode,
            'gerber_file' => $curlFile
        ],
        CURLOPT_RETURNTRANSFER => true
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    if (preg_match('/<h3>JSON<\/h3>\s*<pre>(.*?)<\/pre>/s', $res, $m)) {
        echo "MODE [{$mode}]: " . html_entity_decode(trim($m[1])) . "\n";
    }
}

echo "=== COMPARISON TEST ===\n";
testMode('empty');
testMode('auto');

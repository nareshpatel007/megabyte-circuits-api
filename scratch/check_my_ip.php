<?php
$ch4 = curl_init('https://api.ipify.org?format=json');
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
$res4 = curl_exec($ch4);
curl_close($ch4);

$ch6 = curl_init('https://api.ipify.org?format=json');
curl_setopt($ch6, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch6, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V6);
$res6 = curl_exec($ch6);
curl_close($ch6);

echo "Outbound IPv4: " . $res4 . "\n";
echo "Outbound IPv6: " . ($res6 ?: "None / Failed") . "\n";

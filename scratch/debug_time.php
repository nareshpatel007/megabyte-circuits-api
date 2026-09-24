<?php
$t = time();
echo "Local Unix Timestamp: " . $t . "\n";
echo "Local UTC Time: " . gmdate('Y-m-d H:i:s', $t) . "\n";

$ch = curl_init('https://open.jlcpcb.com');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_TIMEOUT => 5
]);
$res = curl_exec($ch);
curl_close($ch);

if (preg_match('/date: (.*)/i', $res, $m)) {
    $serverTimeStr = trim($m[1]);
    $serverTimestamp = strtotime($serverTimeStr);
    echo "JLCPCB Server Time: " . $serverTimeStr . "\n";
    echo "JLCPCB Server Timestamp: " . $serverTimestamp . "\n";
    echo "Difference (Local - Server): " . ($t - $serverTimestamp) . " seconds\n";
} else {
    echo "Could not fetch JLCPCB server time header.\n";
}

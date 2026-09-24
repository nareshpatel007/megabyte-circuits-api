<?php

$stringToSign = "POST\n/overseas/openapi/pcb/uploadGerber\n1790223410\nMrvw8gwgKnnqPjEZwPsOFAgXFDhbJoWG\n{\"fileName\":\"CAM for AA2121A.ZIP\"}\n";

$secretKey1 = "5tnweyehERnKK7CFWkkyBHqddtcRnENB";

$sig1 = base64_encode(hash_hmac('sha256', $stringToSign, $secretKey1, true));

echo "Signature with 5tnweyehERnKK7CFWkkyBHqddtcRnENB:\n";
echo "   Calculated: " . $sig1 . "\n";
echo "   User Output: xYBI8ofFC/+4mcqOi4ik/moACtbQRqWGPNx9ZYW+vng=\n\n";

if ($sig1 === "xYBI8ofFC/+4mcqOi4ik/moACtbQRqWGPNx9ZYW+vng=") {
    echo "MATCHES! Secret key used by user was 5tnweyehERnKK7CFWkkyBHqddtcRnENB\n";
} else {
    echo "DOES NOT MATCH! Secret key used by user was DIFFERENT!\n";
}

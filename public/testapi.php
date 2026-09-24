<?php

/**
 * JLCPCB Gerber Upload & Online Quotation Tester
 *
 * 1. Upload Gerber ZIP/RAR file to /overseas/openapi/pcb/uploadGerber -> obtain fileKey
 * 2. Calculate PCB quotation via /overseas/openapi/pcb/calculate using the obtained fileKey
 */
$uploadResult = null;
$calcResult = null;
$uploadDebug = null;
$calcDebug = null;

function getServerIPs(): array
{
    $ips = [
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'Not available',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Not available',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'Not available',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Not available',
        'forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'Not available',
        'real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? 'Not available'
    ];
    $ips['server_public_ip'] = getServerPublicIP();
    return $ips;
}

function getServerPublicIP(): string
{
    $cacheFile = sys_get_temp_dir() . '/server_public_ip_cache.txt';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return trim((string)file_get_contents($cacheFile));
    }
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
        'https://checkip.amazonaws.com'
    ];
    foreach ($services as $service) {
        $ch = curl_init($service);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);
        $ip = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ip !== false && $code === 200) {
            $ip = trim($ip);
            file_put_contents($cacheFile, $ip);
            return $ip;
        }
    }
    return 'Unable to determine';
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function generateNonce(int $length = 32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $appId = trim($_POST['app_id'] ?? '', " \t\n\r\0\x0B\"'");
    $accessKey = trim($_POST['access_key'] ?? '', " \t\n\r\0\x0B\"'");
    $secretKey = trim($_POST['secret_key'] ?? '', " \t\n\r\0\x0B\"'");
    $metaJsonOption = $_POST['meta_json_option'] ?? 'empty_string';
    $manualFileKey = trim($_POST['manual_file_key'] ?? '');

    if ($appId === '' || $accessKey === '' || $secretKey === '') {
        $uploadResult = ['ok' => false, 'error' => 'App ID, Access Key and Secret Key are required.'];
    } else {
        $fileKeyToUse = null;

        // Check if user uploaded a file OR provided a manual file key
        if (isset($_FILES['gerber_file']) && $_FILES['gerber_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['gerber_file'];
            $originalName = basename($file['name']);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, ['zip', 'rar'], true)) {
                $uploadResult = ['ok' => false, 'error' => 'Only .zip and .rar Gerber files are allowed.'];
            } else {
                // --- STEP 1: Gerber File Upload ---
                $uploadEndpoint = 'https://open.jlcpcb.com/overseas/openapi/pcb/uploadGerber';
                $uploadMethod = 'POST';
                $uploadPath = parse_url($uploadEndpoint, PHP_URL_PATH);

                $uploadTimestamp = time();
                $uploadNonce = generateNonce(32);

                $curlFile = new CURLFile(
                    $file['tmp_name'],
                    $file['type'] ?: 'application/octet-stream',
                    $originalName
                );

                if ($metaJsonOption === 'empty_json') {
                    $uploadMetaJson = '{}';
                    $postFields = ['fileName' => $originalName, 'file' => $curlFile];
                } elseif ($metaJsonOption === 'json_filename') {
                    $uploadMetaJson = json_encode(['fileName' => $originalName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $postFields = ['fileName' => $originalName, 'file' => $curlFile];
                } else {
                    // Default for Gerber Upload: empty string ""
                    $uploadMetaJson = '';
                    $postFields = ['fileName' => $originalName, 'file' => $curlFile];
                }

                $uploadStringToSign = buildStringToSign(
                    $uploadMethod,
                    $uploadPath,
                    $uploadTimestamp,
                    $uploadNonce,
                    $uploadMetaJson
                );

                $uploadSig = generateSignature($uploadStringToSign, $secretKey);
                $uploadAuth = 'JOP '
                    . 'appid="' . $appId . '",'
                    . 'accesskey="' . $accessKey . '",'
                    . 'nonce="' . $uploadNonce . '",'
                    . 'timestamp="' . $uploadTimestamp . '",'
                    . 'signature="' . $uploadSig . '"';

                $ch = curl_init($uploadEndpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postFields,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . $uploadAuth,
                        'Accept: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_TIMEOUT => 180,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);

                $rawUploadResponse = curl_exec($ch);
                $uploadError = curl_error($ch);
                $uploadErrno = curl_errno($ch);
                $uploadHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $uploadHeaderSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                curl_close($ch);

                $uploadResponseHeaders = '';
                $uploadResponseBody = '';
                if ($rawUploadResponse !== false) {
                    $uploadResponseHeaders = substr($rawUploadResponse, 0, $uploadHeaderSize);
                    $uploadResponseBody = substr($rawUploadResponse, $uploadHeaderSize);
                }

                $uploadDebug = [
                    'endpoint' => $uploadEndpoint,
                    'method' => $uploadMethod,
                    'path' => $uploadPath,
                    'timestamp' => $uploadTimestamp,
                    'nonce' => $uploadNonce,
                    'meta_json' => $uploadMetaJson,
                    'string_to_sign_escaped' => addcslashes($uploadStringToSign, "\n\r\t"),
                    'string_to_sign_sha256' => hash('sha256', $uploadStringToSign),
                    'signature' => $uploadSig,
                    'authorization' => $uploadAuth,
                    'file_name' => $originalName,
                    'file_size' => $file['size'],
                    'http_code' => $uploadHttpCode,
                    'response_headers' => $uploadResponseHeaders,
                ];

                if ($rawUploadResponse === false) {
                    $uploadResult = ['ok' => false, 'error' => "cURL upload error ({$uploadErrno}): {$uploadError}"];
                } else {
                    $uploadDecoded = json_decode($uploadResponseBody, true);
                    $isUploadOk = ($uploadHttpCode >= 200 && $uploadHttpCode < 300 && isset($uploadDecoded['code']) && $uploadDecoded['code'] === 200);
                    $uploadResult = [
                        'ok' => $isUploadOk,
                        'http_code' => $uploadHttpCode,
                        'body' => $uploadResponseBody,
                        'json' => $uploadDecoded,
                        'file_key' => $uploadDecoded['data'] ?? null,
                        'error' => $isUploadOk ? null : ($uploadDecoded['message'] ?? 'Gerber upload failed')
                    ];

                    if ($isUploadOk && !empty($uploadDecoded['data'])) {
                        $fileKeyToUse = $uploadDecoded['data'];
                    }
                }
            }
        } elseif ($manualFileKey !== '') {
            $fileKeyToUse = $manualFileKey;
        } else {
            $uploadResult = ['ok' => false, 'error' => 'Please upload a Gerber file or provide a File Key.'];
        }

        // --- STEP 2: Online PCB Quotation (/overseas/openapi/pcb/calculate) ---
        if ($fileKeyToUse !== null && $fileKeyToUse !== '') {
            $calcEndpoint = 'https://open.jlcpcb.com/overseas/openapi/pcb/calculate';
            $calcMethod = 'POST';
            $calcPath = parse_url($calcEndpoint, PHP_URL_PATH);

            $calcPayload = [
                'orderType' => (int)($_POST['order_type'] ?? 1),
                'pcbParam' => [
                    'layer' => (int)($_POST['layer'] ?? 2),
                    'length' => (float)($_POST['length'] ?? 100),
                    'width' => (float)($_POST['width'] ?? 100),
                    'qty' => (int)($_POST['qty'] ?? 5),
                    'thickness' => (float)($_POST['thickness'] ?? 1.6),
                    'pcbColor' => (int)($_POST['pcb_color'] ?? 0),
                    'surfaceFinish' => (int)($_POST['surface_finish'] ?? 0),
                    'copperWeight' => (float)($_POST['copper_weight'] ?? 1),
                    'goldFinger' => (int)($_POST['gold_finger'] ?? 0),
                    'materialDetails' => (int)($_POST['material_details'] ?? 0),
                    'panelFlag' => (int)($_POST['panel_flag'] ?? 0),
                    'panelByJLCPCB_X' => (int)($_POST['panel_x'] ?? 0),
                    'panelByJLCPCB_Y' => (int)($_POST['panel_y'] ?? 0),
                    'differentDesign' => (int)($_POST['different_design'] ?? 1),
                    'flyingProbeTest' => (int)($_POST['flying_probe_test'] ?? 1),
                    'castellatedHoles' => (int)($_POST['castellated_holes'] ?? 0),
                    'orderDetailsRemark' => trim($_POST['remark'] ?? 'Quotation calculated via testapi.php'),
                    'cascadeStructure' => 0,
                    'impedanceFlag' => 'no',
                    'isAddCustomerCode' => 'nocode',
                    'plateType' => (int)($_POST['plate_type'] ?? 1),
                    'autoConfirmProductionFile' => true,
                    'markOnPcb' => 1,
                    'viaCovering' => 1,
                    'needTechnics' => 0
                ],
                'achieveDate' => (int)($_POST['achieve_date'] ?? 48),
                'fileKey' => $fileKeyToUse
            ];

            $calcBody = json_encode($calcPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $calcTimestamp = time();
            $calcNonce = generateNonce(32);

            $calcStringToSign = buildStringToSign(
                $calcMethod,
                $calcPath,
                $calcTimestamp,
                $calcNonce,
                $calcBody
            );

            $calcSig = generateSignature($calcStringToSign, $secretKey);
            $calcAuth = 'JOP '
                . 'appid="' . $appId . '",'
                . 'accesskey="' . $accessKey . '",'
                . 'nonce="' . $calcNonce . '",'
                . 'timestamp="' . $calcTimestamp . '",'
                . 'signature="' . $calcSig . '"';

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
                CURLOPT_HEADER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $rawCalcResponse = curl_exec($ch);
            $calcError = curl_error($ch);
            $calcErrno = curl_errno($ch);
            $calcHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $calcHeaderSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $calcResponseBody = '';
            if ($rawCalcResponse !== false) {
                $calcResponseBody = substr($rawCalcResponse, $calcHeaderSize);
            }

            $calcDebug = [
                'endpoint' => $calcEndpoint,
                'method' => $calcMethod,
                'path' => $calcPath,
                'timestamp' => $calcTimestamp,
                'nonce' => $calcNonce,
                'body_json' => $calcBody,
                'string_to_sign_escaped' => addcslashes($calcStringToSign, "\n\r\t"),
                'string_to_sign_sha256' => hash('sha256', $calcStringToSign),
                'signature' => $calcSig,
                'authorization' => $calcAuth,
                'http_code' => $calcHttpCode
            ];

            if ($rawCalcResponse === false) {
                $calcResult = ['ok' => false, 'error' => "cURL calculate error ({$calcErrno}): {$calcError}"];
            } else {
                $calcDecoded = json_decode($calcResponseBody, true);
                $isCalcOk = ($calcHttpCode >= 200 && $calcHttpCode < 300 && isset($calcDecoded['code']) && $calcDecoded['code'] === 200);
                $calcResult = [
                    'ok' => $isCalcOk,
                    'http_code' => $calcHttpCode,
                    'body' => $calcResponseBody,
                    'json' => $calcDecoded,
                    'data' => $calcDecoded['data'] ?? null,
                    'error' => $isCalcOk ? null : ($calcDecoded['message'] ?? 'Quotation calculation failed')
                ];
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JLCPCB Gerber Upload & Online Quotation Tester</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            color: #17202a;
            font-family: Arial, Helvetica, sans-serif;
        }

        .container {
            width: min(1100px, calc(100% - 32px));
            margin: 30px auto;
        }

        .card {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }

        h1 {
            margin-top: 0;
            font-size: 26px;
        }

        h2 {
            font-size: 19px;
            margin-top: 0;
            color: #111827;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }

        h3 {
            font-size: 16px;
            margin-top: 15px;
            margin-bottom: 8px;
        }

        label {
            display: block;
            font-weight: 600;
            margin: 12px 0 6px;
            font-size: 14px;
        }

        input[type="text"],
        input[type="password"],
        input[type="number"],
        select,
        input[type="file"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd3dc;
            border-radius: 7px;
            background: #fff;
            font-size: 14px;
        }

        button {
            margin-top: 20px;
            padding: 13px 24px;
            border: 0;
            border-radius: 7px;
            background: #2563eb;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }

        button:hover {
            background: #1d4ed8;
        }

        .hint {
            color: #5f6b78;
            font-size: 13px;
            line-height: 1.4;
        }

        .success {
            border-left: 5px solid #198754;
            padding: 12px;
            background: #eefaf3;
            border-radius: 4px;
        }

        .error {
            border-left: 5px solid #dc3545;
            padding: 12px;
            background: #fff0f1;
            border-radius: 4px;
        }

        pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            background: #111827;
            color: #e5e7eb;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            line-height: 1.5;
            font-size: 13px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 12px;
        }

        .ip-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 14px;
        }

        .ip-table th,
        .ip-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #e0e0e0;
        }

        .ip-table th {
            background-color: #f8fafc;
            font-weight: 600;
        }

        .price-badge {
            display: inline-block;
            background: #059669;
            color: #fff;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 18px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="card">
            <h1>JLCPCB Gerber Upload & Online PCB Quotation Tester</h1>
            <p class="hint">
                This tester combines <strong>Step 1: Upload Gerber File</strong> (<code>/uploadGerber</code>) and
                <strong>Step 2: Calculate PCB Quotation</strong> (<code>/calculate</code>) using the returned <code>fileKey</code>.
            </p>

            <form method="post" enctype="multipart/form-data">
                <h2>1. Authentication Credentials</h2>
                <div class="grid-3">
                    <div>
                        <label for="app_id">App ID</label>
                        <input id="app_id" name="app_id" type="text"
                            value="<?= h($_POST['app_id'] ?? '597733257194213377') ?>"
                            placeholder="Your JLCPCB App ID" required>
                    </div>

                    <div>
                        <label for="access_key">Access Key</label>
                        <input id="access_key" name="access_key" type="text"
                            value="<?= h($_POST['access_key'] ?? 'e37cf942e8a84fbfbfa4e63910aaa9b1') ?>"
                            placeholder="Your JLCPCB Access Key" required>
                    </div>

                    <div>
                        <label for="secret_key">Secret Key</label>
                        <input id="secret_key" name="secret_key" type="password"
                            value="<?= h($_POST['secret_key'] ?? '5tnweyehERnKK7CFWkkyBHqddtcRnENB') ?>"
                            placeholder="Your JLCPCB Secret Key" required>
                    </div>
                </div>

                <h2>2. Gerber File & Parameters</h2>
                <div class="grid-2">
                    <div>
                        <label for="gerber_file">Gerber File (.zip or .rar)</label>
                        <input id="gerber_file" name="gerber_file" type="file"
                            accept=".zip,.rar,application/zip,application/x-rar-compressed">
                        <span class="hint">Select a Gerber zip/rar file to upload & get quotation</span>
                    </div>

                    <div>
                        <label for="manual_file_key">Or Manual File Key (Optional)</label>
                        <input id="manual_file_key" name="manual_file_key" type="text"
                            value="<?= h($_POST['manual_file_key'] ?? '') ?>"
                            placeholder="e.g. 7909f88464594a1f944677417f191de0">
                        <span class="hint">Skip upload if you already have a valid fileKey</span>
                    </div>
                </div>

                <h2>3. PCB Quotation Parameters (Required & Default Parameters)</h2>
                <div class="grid-4">
                    <div>
                        <label for="layer">Layers</label>
                        <select id="layer" name="layer">
                            <option value="1" <?= ($_POST['layer'] ?? '2') == '1' ? 'selected' : '' ?>>1 Layer</option>
                            <option value="2" <?= ($_POST['layer'] ?? '2') == '2' ? 'selected' : '' ?>>2 Layers</option>
                            <option value="4" <?= ($_POST['layer'] ?? '2') == '4' ? 'selected' : '' ?>>4 Layers</option>
                            <option value="6" <?= ($_POST['layer'] ?? '2') == '6' ? 'selected' : '' ?>>6 Layers</option>
                        </select>
                    </div>

                    <div>
                        <label for="length">Length (mm)</label>
                        <input id="length" name="length" type="number" step="0.1" value="<?= h($_POST['length'] ?? '100') ?>" required>
                    </div>

                    <div>
                        <label for="width">Width (mm)</label>
                        <input id="width" name="width" type="number" step="0.1" value="<?= h($_POST['width'] ?? '100') ?>" required>
                    </div>

                    <div>
                        <label for="qty">Quantity</label>
                        <input id="qty" name="qty" type="number" value="<?= h($_POST['qty'] ?? '5') ?>" required>
                    </div>
                </div>

                <div class="grid-4" style="margin-top: 10px;">
                    <div>
                        <label for="thickness">Thickness (mm)</label>
                        <select id="thickness" name="thickness">
                            <option value="0.8" <?= ($_POST['thickness'] ?? '1.6') == '0.8' ? 'selected' : '' ?>>0.8 mm</option>
                            <option value="1.0" <?= ($_POST['thickness'] ?? '1.6') == '1.0' ? 'selected' : '' ?>>1.0 mm</option>
                            <option value="1.2" <?= ($_POST['thickness'] ?? '1.6') == '1.2' ? 'selected' : '' ?>>1.2 mm</option>
                            <option value="1.6" <?= ($_POST['thickness'] ?? '1.6') == '1.6' ? 'selected' : '' ?>>1.6 mm</option>
                            <option value="2.0" <?= ($_POST['thickness'] ?? '1.6') == '2.0' ? 'selected' : '' ?>>2.0 mm</option>
                        </select>
                    </div>

                    <div>
                        <label for="pcb_color">PCB Color</label>
                        <select id="pcb_color" name="pcb_color">
                            <option value="0" <?= ($_POST['pcb_color'] ?? '0') == '0' ? 'selected' : '' ?>>Green</option>
                            <option value="1" <?= ($_POST['pcb_color'] ?? '0') == '1' ? 'selected' : '' ?>>Red</option>
                            <option value="2" <?= ($_POST['pcb_color'] ?? '0') == '2' ? 'selected' : '' ?>>Yellow</option>
                            <option value="3" <?= ($_POST['pcb_color'] ?? '0') == '3' ? 'selected' : '' ?>>Blue</option>
                            <option value="4" <?= ($_POST['pcb_color'] ?? '0') == '4' ? 'selected' : '' ?>>White</option>
                            <option value="5" <?= ($_POST['pcb_color'] ?? '0') == '5' ? 'selected' : '' ?>>Black</option>
                            <option value="6" <?= ($_POST['pcb_color'] ?? '0') == '6' ? 'selected' : '' ?>>Purple</option>
                        </select>
                    </div>

                    <div>
                        <label for="surface_finish">Surface Finish</label>
                        <select id="surface_finish" name="surface_finish">
                            <option value="0" <?= ($_POST['surface_finish'] ?? '0') == '0' ? 'selected' : '' ?>>HASL with lead</option>
                            <option value="1" <?= ($_POST['surface_finish'] ?? '0') == '1' ? 'selected' : '' ?>>Lead-free HASL</option>
                            <option value="2" <?= ($_POST['surface_finish'] ?? '0') == '2' ? 'selected' : '' ?>>ENIG</option>
                        </select>
                    </div>

                    <div>
                        <label for="copper_weight">Copper Weight (oz)</label>
                        <select id="copper_weight" name="copper_weight">
                            <option value="1" <?= ($_POST['copper_weight'] ?? '1') == '1' ? 'selected' : '' ?>>1 oz</option>
                            <option value="2" <?= ($_POST['copper_weight'] ?? '1') == '2' ? 'selected' : '' ?>>2 oz</option>
                        </select>
                    </div>
                </div>

                <div class="grid-3" style="margin-top: 10px;">
                    <div>
                        <label for="achieve_date">Lead Time (Hours)</label>
                        <select id="achieve_date" name="achieve_date">
                            <option value="24" <?= ($_POST['achieve_date'] ?? '48') == '24' ? 'selected' : '' ?>>24 Hours (Urgent)</option>
                            <option value="48" <?= ($_POST['achieve_date'] ?? '48') == '48' ? 'selected' : '' ?>>48 Hours (Standard)</option>
                            <option value="120" <?= ($_POST['achieve_date'] ?? '48') == '120' ? 'selected' : '' ?>>120 Hours (Economy)</option>
                        </select>
                    </div>

                    <div>
                        <label for="plate_type">Base Material</label>
                        <select id="plate_type" name="plate_type">
                            <option value="1" <?= ($_POST['plate_type'] ?? '1') == '1' ? 'selected' : '' ?>>FR-4</option>
                            <option value="2" <?= ($_POST['plate_type'] ?? '1') == '2' ? 'selected' : '' ?>>Aluminum</option>
                            <option value="4" <?= ($_POST['plate_type'] ?? '1') == '4' ? 'selected' : '' ?>>Copper Core</option>
                        </select>
                    </div>

                    <div>
                        <label for="meta_json_option">Upload Signature Body</label>
                        <select id="meta_json_option" name="meta_json_option">
                            <option value="empty_string" <?= ($_POST['meta_json_option'] ?? 'empty_string') === 'empty_string' ? 'selected' : '' ?>>Empty String ("") - Recommended</option>
                            <option value="json_filename" <?= ($_POST['meta_json_option'] ?? '') === 'json_filename' ? 'selected' : '' ?>>JSON ({"fileName":"..."})</option>
                            <option value="empty_json" <?= ($_POST['meta_json_option'] ?? '') === 'empty_json' ? 'selected' : '' ?>>Empty JSON ({})</option>
                        </select>
                    </div>
                </div>

                <button type="submit">Upload Gerber & Calculate Quotation</button>
            </form>
        </div>

        <?php if ($uploadResult !== null): ?>
            <div class="card">
                <h2>Step 1: Gerber Upload Result (/uploadGerber)</h2>

                <?php if ($uploadResult['ok']): ?>
                    <div class="success">
                        Gerber File Uploaded Successfully! HTTP status:
                        <strong><?= h($uploadResult['http_code']) ?></strong>
                        <br>Obtained File Key: <code><?= h($uploadResult['file_key']) ?></code>
                    </div>
                <?php else: ?>
                    <div class="error">
                        Gerber Upload Failed. HTTP status: <strong><?= h($uploadResult['http_code'] ?? 'N/A') ?></strong>
                        <?php if (!empty($uploadResult['error'])): ?>
                            <br><?= h($uploadResult['error']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($uploadResult['json'])): ?>
                    <h3>Upload Response JSON</h3>
                    <pre><?= h(json_encode($uploadResult['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($calcResult !== null): ?>
            <div class="card">
                <h2>Step 2: Online PCB Quotation Result (/calculate)</h2>

                <?php if ($calcResult['ok']): ?>
                    <div class="success">
                        Quotation Calculated Successfully! HTTP status:
                        <strong><?= h($calcResult['http_code']) ?></strong>
                    </div>

                    <?php if (!empty($calcResult['data'])): $data = $calcResult['data']; ?>
                        <div style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                <div>
                                    <h3 style="margin: 0 0 5px; font-size: 18px;">Total PCB Cost Excl. Shipping</h3>
                                    <span class="hint">Total Weight: <?= h($data['orderTotalWeight'] ?? 'N/A') ?> g</span>
                                </div>
                                <div>
                                    <span class="price-badge">$<?= h(number_format((float)($data['priceWithoutFreight'] ?? 0), 2)) ?></span>
                                </div>
                            </div>

                            <?php if (!empty($data['pcbCostInfo'])): $cost = $data['pcbCostInfo']; ?>
                                <h3>PCB Fee Breakdown</h3>
                                <table class="ip-table">
                                    <tr>
                                        <th>Fee Component</th>
                                        <th>Amount</th>
                                    </tr>
                                    <tr><td>Engineering / Project Fee</td><td>$<?= h(number_format((float)($cost['projectFee'] ?? 0), 2)) ?></td></tr>
                                    <tr><td>Board Fee</td><td>$<?= h(number_format((float)($cost['totalFee'] ?? 0), 2)) ?></td></tr>
                                    <tr><td>Testing Fee</td><td>$<?= h(number_format((float)($cost['testsFee'] ?? 0), 2)) ?></td></tr>
                                    <tr><td>Film Fee</td><td>$<?= h(number_format((float)($cost['fillFee'] ?? 0), 2)) ?></td></tr>
                                </table>
                            <?php endif; ?>

                            <?php if (!empty($data['shipList'])): ?>
                                <h3>Shipping Options Available</h3>
                                <table class="ip-table">
                                    <tr>
                                        <th>Shipping Method</th>
                                        <th>Details</th>
                                        <th>Cost</th>
                                        <th>Delivery Days</th>
                                    </tr>
                                    <?php foreach ($data['shipList'] as $ship): ?>
                                        <tr>
                                            <td><strong><?= h($ship['options'] ?? '') ?></strong></td>
                                            <td><?= h($ship['showOptions'] ?? '') ?></td>
                                            <td>$<?= h(number_format((float)($ship['cost'] ?? 0), 2)) ?></td>
                                            <td><?= h($ship['day'] ?? '') ?> days</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>

                            <?php if (!empty($data['achieveDateList'])): ?>
                                <h3>Lead Time Options</h3>
                                <table class="ip-table">
                                    <tr>
                                        <th>Build Option</th>
                                        <th>Lead Time (Hours)</th>
                                        <th>Selected?</th>
                                        <th>Expedited Fee</th>
                                    </tr>
                                    <?php foreach ($data['achieveDateList'] as $achieve): ?>
                                        <tr>
                                            <td><?= h($achieve['achieveName'] ?? '') ?></td>
                                            <td><?= h($achieve['achieveDate'] ?? '') ?> hours</td>
                                            <td><?= ($achieve['achieveChecked'] ?? false) ? '✅ Selected' : 'No' ?></td>
                                            <td>$<?= h(number_format((float)($achieve['achievePrice'] ?? 0), 2)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="error">
                        Quotation Calculation Failed. HTTP status: <strong><?= h($calcResult['http_code'] ?? 'N/A') ?></strong>
                        <?php if (!empty($calcResult['error'])): ?>
                            <br><?= h($calcResult['error']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($calcResult['json'])): ?>
                    <h3>Quotation Response Raw JSON</h3>
                    <pre><?= h(json_encode($calcResult['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($calcDebug !== null): ?>
            <div class="card">
                <h2>Quotation API Signature Debug (/calculate)</h2>
                <table class="ip-table">
                    <tr><th>Parameter</th><th>Signed Value</th></tr>
                    <tr><td>HTTP Method</td><td><?= h($calcDebug['method']) ?></td></tr>
                    <tr><td>Request Path</td><td><?= h($calcDebug['path']) ?></td></tr>
                    <tr><td>Timestamp</td><td><?= h($calcDebug['timestamp']) ?></td></tr>
                    <tr><td>Nonce</td><td><?= h($calcDebug['nonce']) ?></td></tr>
                    <tr><td>JSON Payload Signed</td><td><code><?= h($calcDebug['body_json']) ?></code></td></tr>
                    <tr><td>Generated Base64 Signature</td><td><code><?= h($calcDebug['signature']) ?></code></td></tr>
                    <tr><td>Authorization Header</td><td><code><?= h($calcDebug['authorization']) ?></code></td></tr>
                </table>

                <h3>Escaped String to Sign (\n view)</h3>
                <pre><?= h($calcDebug['string_to_sign_escaped']) ?></pre>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>
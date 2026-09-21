<?php

/**
 * JLCPCB Gerber Upload Tester - PHP only
 *
 * JLCPCB support confirmed that the Upload Gerber signing/meta JSON is exactly {}.
 * The actual request remains multipart/form-data with fileName + file.
 */
$result = null;
$requestDebug = null;

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
    // JLCPCB format: five lines with a final newline.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appId = trim($_POST['app_id'] ?? '');
    $accessKey = trim($_POST['access_key'] ?? '');
    $secretKey = trim($_POST['secret_key'] ?? '');

    if ($appId === '' || $accessKey === '' || $secretKey === '') {
        $result = ['ok' => false, 'error' => 'App ID, Access Key and Secret Key are required.'];
    } elseif (!isset($_FILES['gerber_file'])) {
        $result = ['ok' => false, 'error' => 'Please select a Gerber ZIP/RAR file.'];
    } else {
        $file = $_FILES['gerber_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result = ['ok' => false, 'error' => 'PHP upload error code: ' . $file['error']];
        } else {
            $originalName = basename($file['name']);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['zip', 'rar'], true)) {
                $result = ['ok' => false, 'error' => 'Only .zip and .rar Gerber files are allowed.'];
            } else {
                $endpoint = 'https://open.jlcpcb.com/overseas/openapi/pcb/uploadGerber';
                $method = 'POST';
                $urlPath = parse_url($endpoint, PHP_URL_PATH);
                $urlQuery = parse_url($endpoint, PHP_URL_QUERY);
                if ($urlQuery !== null && $urlQuery !== '') {
                    $urlPath .= '?' . $urlQuery;
                }

                $timestamp = time();
                $nonce = generateNonce(32);

                // CRITICAL: JLCPCB support confirmed this exact value.
                $metaJson = '{}';

                $stringToSign = buildStringToSign(
                    $method,
                    $urlPath,
                    $timestamp,
                    $nonce,
                    $metaJson
                );

                $signature = generateSignature($stringToSign, $secretKey);
                $authorization = 'JOP '
                    . 'appid="' . $appId . '",'
                    . 'accesskey="' . $accessKey . '",'
                    . 'nonce="' . $nonce . '",'
                    . 'timestamp="' . $timestamp . '",'
                    . 'signature="' . $signature . '"';

                $curlFile = new CURLFile(
                    $file['tmp_name'],
                    $file['type'] ?: 'application/octet-stream',
                    $originalName
                );

                $postFields = [
                    'fileName' => $originalName,
                    'file' => $curlFile
                ];

                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postFields,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . $authorization,
                        'Accept: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_TIMEOUT => 180,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);

                $rawResponse = curl_exec($ch);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $localIP = curl_getinfo($ch, CURLINFO_LOCAL_IP);
                $localPort = curl_getinfo($ch, CURLINFO_LOCAL_PORT);
                $primaryIP = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
                $primaryPort = curl_getinfo($ch, CURLINFO_PRIMARY_PORT);
                curl_close($ch);

                $responseHeaders = '';
                $responseBody = '';
                if ($rawResponse !== false) {
                    $responseHeaders = substr($rawResponse, 0, $headerSize);
                    $responseBody = substr($rawResponse, $headerSize);
                }

                $requestDebug = [
                    'execution_mode' => 'PHP Native cURL ONLY',
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'path' => $urlPath,
                    'timestamp' => $timestamp,
                    'nonce' => $nonce,
                    'meta_json' => $metaJson,
                    'string_to_sign' => $stringToSign,
                    'string_to_sign_sha256' => hash('sha256', $stringToSign),
                    'signature' => $signature,
                    'authorization' => $authorization,
                    'file_name' => $originalName,
                    'file_size' => $file['size'],
                    'http_code' => $httpCode,
                    'response_headers' => $responseHeaders,
                    'server_ips' => getServerIPs(),
                    'connection_info' => [
                        'local_ip' => $localIP,
                        'local_port' => $localPort,
                        'primary_ip' => $primaryIP,
                        'primary_port' => $primaryPort
                    ]
                ];

                if ($rawResponse === false) {
                    $result = ['ok' => false, 'error' => "cURL error ({$curlErrno}): {$curlError}"];
                } else {
                    $decoded = json_decode($responseBody, true);
                    $result = [
                        'ok' => ($httpCode >= 200 && $httpCode < 300),
                        'http_code' => $httpCode,
                        'body' => $responseBody,
                        'json' => $decoded,
                        'error' => null
                    ];
                }
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
    <title>JLCPCB Gerber Upload Tester</title>
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
            width: min(1000px, calc(100% - 32px));
            margin: 40px auto;
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
        }

        label {
            display: block;
            font-weight: 600;
            margin: 14px 0 7px;
        }

        input[type="text"],
        input[type="password"],
        input[type="file"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd3dc;
            border-radius: 7px;
            background: #fff;
        }

        button {
            margin-top: 20px;
            padding: 12px 20px;
            border: 0;
            border-radius: 7px;
            background: #111827;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            opacity: .9;
        }

        .hint {
            color: #5f6b78;
            font-size: 14px;
            line-height: 1.5;
        }

        .success {
            border-left: 5px solid #198754;
            padding: 12px;
            background: #eefaf3;
        }

        .error {
            border-left: 5px solid #dc3545;
            padding: 12px;
            background: #fff0f1;
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
        }

        .warning {
            background: #fff8e1;
            border: 1px solid #f0d77a;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.5;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .ip-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        .ip-table th,
        .ip-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #e0e0e0;
        }

        .ip-table th {
            background-color: #f5f5f5;
            font-weight: 600;
        }

        .ip-table tr:nth-child(even) {
            background-color: #fafafa;
        }

        @media (max-width: 700px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="card">
            <h1>JLCPCB Gerber Upload Tester</h1>

            <p class="hint">
                Upload a Gerber ZIP/RAR file and this page will generate the JOP
                Authorization header using App ID, Access Key, and Secret Key,
                then call the JLCPCB
                <code>/overseas/openapi/pcb/uploadGerber</code> endpoint.
            </p>

            <div class="warning">
                <strong>Security:</strong> The Secret Key is used only by this
                server-side PHP script to calculate the JOP signature. It is not
                sent to JLCPCB and is never displayed in the debug output.
                Do not expose this testing page publicly without authentication
                and HTTPS.
            </div>

            <?php
            // Display current server IP information
            $currentServerIPs = getServerIPs();
            ?>
            <h3>Current Server IP Information</h3>
            <table class="ip-table">
                <tr>
                    <th>IP Type</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Public IP Address</td>
                    <td><?= h($currentServerIPs['server_public_ip']) ?></td>
                </tr>
                <tr>
                    <td>Server Address (SERVER_ADDR)</td>
                    <td><?= h($currentServerIPs['server_addr']) ?></td>
                </tr>
                <tr>
                    <td>Remote Address (REMOTE_ADDR)</td>
                    <td><?= h($currentServerIPs['remote_addr']) ?></td>
                </tr>
                <tr>
                    <td>Server Name</td>
                    <td><?= h($currentServerIPs['server_name']) ?></td>
                </tr>
                <tr>
                    <td>HTTP Host</td>
                    <td><?= h($currentServerIPs['http_host']) ?></td>
                </tr>
            </table>

            <form method="post" enctype="multipart/form-data">
                <div class="grid">
                    <div>
                        <label for="app_id">App ID</label>
                        <input id="app_id" name="app_id" type="text"
                            value="<?= h($_POST['app_id'] ?? '') ?>"
                            placeholder="Your JLCPCB App ID" required>
                    </div>

                    <div>
                        <label for="access_key">Access Key</label>
                        <input id="access_key" name="access_key" type="text"
                            value="<?= h($_POST['access_key'] ?? '') ?>"
                            placeholder="Your JLCPCB Access Key" required>
                    </div>
                </div>

                <div class="grid" style="margin-top: 15px;">
                    <div>
                        <label for="secret_key">Secret Key</label>
                        <input id="secret_key" name="secret_key" type="password" value="" placeholder="Your JLCPCB Secret Key" required>
                    </div>
                    <div>
                        <label>Signature Meta JSON</label>
                        <input type="text" value="{}" readonly style="width:100%;padding:11px 12px;border:1px solid #cbd3dc;border-radius:7px;background:#f5f5f5;">
                    </div>
                </div>
                <p class="hint">JLCPCB support confirmed the Upload Gerber signing/meta JSON is exactly <code>{}</code>.</p>

                <label for="gerber_file">Gerber File (.zip or .rar)</label>
                <input id="gerber_file" name="gerber_file" type="file"
                    accept=".zip,.rar,application/zip,application/x-rar-compressed"
                    required>

                <button type="submit">Upload Gerber & Get Response</button>
            </form>
        </div>

        <?php if ($result !== null): ?>
            <div class="card">
                <h2>API Response</h2>

                <?php if ($result['ok']): ?>
                    <div class="success">
                        Request completed. HTTP status:
                        <strong><?= h($result['http_code']) ?></strong>
                    </div>
                <?php else: ?>
                    <div class="error">
                        Request failed.
                        <?php if (!empty($result['http_code'])): ?>
                            HTTP status: <strong><?= h($result['http_code']) ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($result['error'])): ?>
                            <br><?= h($result['error']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($result['json'])): ?>
                    <h3>JSON</h3>
                    <pre><?= h(json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php else: ?>
                    <h3>Raw Response</h3>
                    <pre><?= h($result['body'] ?? '') ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($requestDebug !== null): ?>
            <div class="card">
                <h2>Request Debug Information</h2>

                <p class="hint">
                    This is useful when comparing the request with JLCPCB's
                    authentication documentation.
                </p>

                <h3>Server IP Information</h3>
                <table class="ip-table">
                    <tr>
                        <th>IP Type</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>Public IP Address</td>
                        <td><?= h($requestDebug['server_ips']['server_public_ip']) ?></td>
                    </tr>
                    <tr>
                        <td>Server Address (SERVER_ADDR)</td>
                        <td><?= h($requestDebug['server_ips']['server_addr']) ?></td>
                    </tr>
                    <tr>
                        <td>Remote Address (REMOTE_ADDR)</td>
                        <td><?= h($requestDebug['server_ips']['remote_addr']) ?></td>
                    </tr>
                    <tr>
                        <td>Server Name</td>
                        <td><?= h($requestDebug['server_ips']['server_name']) ?></td>
                    </tr>
                    <tr>
                        <td>HTTP Host</td>
                        <td><?= h($requestDebug['server_ips']['http_host']) ?></td>
                    </tr>
                    <tr>
                        <td>Forwarded For</td>
                        <td><?= h($requestDebug['server_ips']['forwarded_for']) ?></td>
                    </tr>
                    <tr>
                        <td>Real IP (X-Real-IP)</td>
                        <td><?= h($requestDebug['server_ips']['real_ip']) ?></td>
                    </tr>
                </table>

                <h3>Connection Information</h3>
                <table class="ip-table">
                    <tr>
                        <th>Connection Detail</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>Local IP (Outgoing)</td>
                        <td><?= h($requestDebug['connection_info']['local_ip'] ?? 'Not available') ?></td>
                    </tr>
                    <tr>
                        <td>Local Port</td>
                        <td><?= h($requestDebug['connection_info']['local_port'] ?? 'Not available') ?></td>
                    </tr>
                    <tr>
                        <td>JLCPCB Server IP</td>
                        <td><?= h($requestDebug['connection_info']['primary_ip'] ?? 'Not available') ?></td>
                    </tr>
                    <tr>
                        <td>JLCPCB Server Port</td>
                        <td><?= h($requestDebug['connection_info']['primary_port'] ?? 'Not available') ?></td>
                    </tr>
                </table>

                <h3>Endpoint</h3>
                <pre><?= h($requestDebug['endpoint']) ?></pre>

                <h3>Timestamp</h3>
                <pre><?= h($requestDebug['timestamp']) ?></pre>

                <h3>Nonce</h3>
                <pre><?= h($requestDebug['nonce']) ?></pre>

                <h3>Exact Meta JSON used for signature</h3>
                <pre><?= h($requestDebug['meta_json']) ?></pre>

                <h3>String to Sign</h3>
                <pre><?= h($requestDebug['string_to_sign']) ?></pre>

                <h3>SHA-256 of Exact String to Sign</h3>
                <pre><?= h($requestDebug['string_to_sign_sha256'] ?? '') ?></pre>

                <h3>Generated Signature</h3>
                <pre><?= h($requestDebug['signature']) ?></pre>

                <h3>Authorization Header</h3>
                <pre><?= h($requestDebug['authorization']) ?></pre>

                <h3>HTTP Response Headers</h3>
                <pre><?= h($requestDebug['response_headers']) ?></pre>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>
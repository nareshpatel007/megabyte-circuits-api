<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class JlcpcbService
{
    protected string $baseUrl;
    protected ?string $appId;
    protected ?string $accessKey;
    protected ?string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(CredentialService::get('jlcpcb', 'JLCPCB_BASE_URL', 'JLCPCB_BASE_URL', config('services.jlcpcb.base_url', 'https://open.jlcpcb.com')), '/');
        $this->appId = CredentialService::get('jlcpcb', 'JLCPCB_APP_ID', 'JLCPCB_APP_ID', config('services.jlcpcb.app_id'));
        $this->accessKey = CredentialService::get('jlcpcb', 'JLCPCB_ACCESS_KEY', 'JLCPCB_ACCESS_KEY', config('services.jlcpcb.access_key'));
        $this->secretKey = CredentialService::get('jlcpcb', 'JLCPCB_SECRET_KEY', 'JLCPCB_SECRET_KEY', config('services.jlcpcb.secret_key'));
    }

    /**
     * Generate JOP HMAC-SHA256 signature and Authorization header
     */
    public function generateJopAuthorization(
        string $method,
        string $urlPath,
        string $metaJson,
        ?string $appId = null,
        ?string $accessKey = null,
        ?string $secretKey = null
    ): array {
        $appId = trim((string)($appId ?? $this->appId ?? config('services.jlcpcb.app_id')), " \t\n\r\0\x0B\"'");
        $accessKey = trim((string)($accessKey ?? $this->accessKey ?? config('services.jlcpcb.access_key')), " \t\n\r\0\x0B\"'");
        $secretKey = trim((string)($secretKey ?? $this->secretKey ?? config('services.jlcpcb.secret_key')), " \t\n\r\0\x0B\"'");

        if (empty($appId)) {
            throw new Exception("JLCPCB App ID (JLCPCB_APP_ID) is missing or empty in environment configuration.");
        }
        if (empty($accessKey)) {
            throw new Exception("JLCPCB Access Key (JLCPCB_ACCESS_KEY) is missing or empty in environment configuration.");
        }
        if (empty($secretKey)) {
            throw new Exception("JLCPCB Secret Key (JLCPCB_SECRET_KEY) is missing or empty in environment configuration.");
        }

        $timestamp = time();
        
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < 32; $i++) {
            $nonce .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $stringToSign = $method . "\n"
            . $urlPath . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . $metaJson . "\n";

        $hash = hash_hmac('sha256', $stringToSign, $secretKey, true);
        $signature = base64_encode($hash);

        $authorization = 'JOP ' .
            'appid="' . $appId . '",' .
            'accesskey="' . $accessKey . '",' .
            'nonce="' . $nonce . '",' .
            'timestamp="' . $timestamp . '",' .
            'signature="' . $signature . '"';

        return [
            'app_id' => $appId,
            'access_key' => $accessKey,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'meta_json' => $metaJson,
            'string_to_sign' => $stringToSign,
            'signature' => $signature,
            'authorization' => $authorization
        ];
    }

    /**
     * Upload PCB Gerber file (ZIP/RAR) to JLCPCB Open API
     * Endpoint: POST /overseas/openapi/pcb/uploadGerber
     *
     * @param mixed $file File object or path to Gerber zip/rar file
     * @param string|null $fileName Optional file name override
     * @param string|null $appId
     * @param string|null $accessKey
     * @param string|null $secretKey
     * @param string|null $metaJsonOverride
     * @return array
     * @throws Exception
     */
    public function uploadGerber(
        $file,
        ?string $fileName = null,
        ?string $appId = null,
        ?string $accessKey = null,
        ?string $secretKey = null,
        ?string $metaJsonOverride = null
    ): array {
        $endpoint = "{$this->baseUrl}/overseas/openapi/pcb/uploadGerber";
        $urlPath = parse_url($endpoint, PHP_URL_PATH);

        if (is_string($file) && file_exists($file)) {
            $filePath = $file;
            $originalName = $fileName ?? basename($file);
        } elseif ($file instanceof \Illuminate\Http\UploadedFile) {
            $filePath = $file->getRealPath();
            $originalName = $fileName ?? $file->getClientOriginalName();
        } else {
            throw new Exception("Invalid file provided for Gerber upload.");
        }

        // JLCPCB signature rule for /overseas/openapi/pcb/uploadGerber:
        // The body line in string to sign for file uploads is empty string ("")
        $metaJson = $metaJsonOverride !== null ? $metaJsonOverride : '';

        $authData = $this->generateJopAuthorization(
            'POST',
            $urlPath,
            $metaJson,
            $appId,
            $accessKey,
            $secretKey
        );

        $clientIp = $this->getRequestClientIp();
        $outboundIp = $this->getOutboundPublicIp();
        $ipStr = $this->getLogIpString();

        try {
            $curlFile = new \CURLFile(
                $filePath,
                mime_content_type($filePath) ?: 'application/octet-stream',
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
                    'Authorization: ' . $authData['authorization'],
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

            if ($rawUploadResponse === false) {
                Log::error("JLCPCB Upload Gerber cURL Error [{$ipStr}]: ({$uploadErrno}) {$uploadError}");
                return [
                    'success' => false,
                    'code' => 500,
                    'message' => "cURL upload error ({$uploadErrno}): {$uploadError}",
                    'data' => null,
                    'request_client_ip' => $clientIp,
                    'outbound_ip' => $outboundIp,
                    'auth_debug' => $authData
                ];
            }

            $uploadResponseBody = substr($rawUploadResponse, $uploadHeaderSize);
            $result = json_decode($uploadResponseBody, true);

            Log::info("JLCPCB Upload Gerber Response [{$ipStr}]", [
                'request_client_ip' => $clientIp,
                'outbound_ip' => $outboundIp,
                'status' => $uploadHttpCode,
                'result' => $result
            ]);

            if (is_array($result)) {
                $code = $result['code'] ?? null;
                if ($code === 200) {
                    return [
                        'success' => true,
                        'code' => 200,
                        'message' => 'Gerber file uploaded successfully',
                        'fileKey' => $result['data'] ?? '',
                        'data' => $result['data'] ?? '',
                        'request_client_ip' => $clientIp,
                        'outbound_ip' => $outboundIp,
                        'auth_debug' => $authData
                    ];
                }

                $errorMessage = $result['message'] ?? $this->getErrorMessageByCode($code);
                return [
                    'success' => false,
                    'code' => $code ?? $uploadHttpCode,
                    'message' => $errorMessage,
                    'data' => null,
                    'request_client_ip' => $clientIp,
                    'outbound_ip' => $outboundIp,
                    'auth_debug' => $authData
                ];
            }

            return [
                'success' => false,
                'code' => $uploadHttpCode,
                'message' => 'Unexpected API response format during Gerber upload.',
                'raw_response' => $uploadResponseBody,
                'request_client_ip' => $clientIp,
                'outbound_ip' => $outboundIp,
                'auth_debug' => $authData
            ];

        } catch (Exception $e) {
            Log::error("JLCPCB Upload Gerber Exception [{$ipStr}]: " . $e->getMessage(), [
                'request_client_ip' => $clientIp,
                'outbound_ip' => $outboundIp
            ]);
            throw new Exception("Error uploading Gerber file to JLCPCB: " . $e->getMessage());
        }
    }

    /**
     * Calculate PCB quotation via JLCPCB Open API
     * Endpoint: POST /overseas/openapi/pcb/calculate
     *
     * @param array $input
     * @return array
     * @throws Exception
     */
    public function calculateQuotation(array $input): array
    {
        // 1. Resolve gerber_id / gerber_file_id to retrieve stored jlcpcb_file_key from DB if not passed directly
        if (empty($input['fileKey']) && (!empty($input['gerber_id']) || !empty($input['gerber_file_id']))) {
            $gerberId = $input['gerber_id'] ?? $input['gerber_file_id'];
            $gerberRecord = \Illuminate\Support\Facades\DB::table('gerber_files')->where('id', $gerberId)->first();
            if ($gerberRecord) {
                if (!empty($gerberRecord->jlcpcb_file_key)) {
                    $input['fileKey'] = $gerberRecord->jlcpcb_file_key;
                } elseif ($gerberRecord->file_path && file_exists(storage_path('app/public/' . $gerberRecord->file_path))) {
                    try {
                        $fullPath = storage_path('app/public/' . $gerberRecord->file_path);
                        $uploadRes = $this->uploadGerber($fullPath, $gerberRecord->original_name);
                        if (!empty($uploadRes['success']) && !empty($uploadRes['fileKey'])) {
                            $input['fileKey'] = $uploadRes['fileKey'];
                            \Illuminate\Support\Facades\DB::table('gerber_files')->where('id', $gerberId)->update([
                                'jlcpcb_file_key' => $uploadRes['fileKey'],
                                'jlcpcb_upload_status' => 'completed',
                                'jlcpcb_uploaded_at' => date('Y-m-d H:i:s'),
                                'jlcpcb_upload_error' => null
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::warning("On-demand JLCPCB upload failed for Gerber ID {$gerberId}: " . $e->getMessage());
                    }
                }
            }
        }

        $endpoint = "{$this->baseUrl}/overseas/openapi/pcb/calculate";
        $urlPath = parse_url($endpoint, PHP_URL_PATH);

        // Merge input with default structured parameters
        $payload = $this->buildPayload($input);
        $calcBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $clientIp = $this->getRequestClientIp();
        $outboundIp = $this->getOutboundPublicIp();
        $ipStr = $this->getLogIpString();

        Log::info("JLCPCB Calculate Request [{$ipStr}]", [
            'request_client_ip' => $clientIp,
            'outbound_ip' => $outboundIp,
            'url' => $endpoint,
            'payload' => $payload
        ]);

        // Generate JOP Authorization header if secretKey is available, else fallback to Bearer header
        if (!empty($this->secretKey)) {
            $authData = $this->generateJopAuthorization('POST', $urlPath, $calcBody);
            $authHeader = $authData['authorization'];
        } elseif (!empty($this->accessKey)) {
            $authHeader = (str_starts_with(strtolower($this->accessKey), 'bearer ')) 
                ? $this->accessKey 
                : "Bearer {$this->accessKey}";
        } else {
            throw new Exception("JLCPCB credentials are not configured in environment.");
        }

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $calcBody,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $authHeader,
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

            if ($rawCalcResponse === false) {
                Log::error("JLCPCB Calculate cURL Error [{$ipStr}]: ({$calcErrno}) {$calcError}");
                return [
                    'success' => false,
                    'source' => 'jlcpcb',
                    'code' => 500,
                    'message' => "cURL calculate error ({$calcErrno}): {$calcError}",
                    'raw_response' => null
                ];
            }

            $calcResponseBody = substr($rawCalcResponse, $calcHeaderSize);
            $result = json_decode($calcResponseBody, true);

            Log::info("JLCPCB Calculate Response [{$ipStr}]", [
                'request_client_ip' => $clientIp,
                'outbound_ip' => $outboundIp,
                'status' => $calcHttpCode,
                'result' => $result
            ]);

            if (is_array($result)) {
                $code = $result['code'] ?? null;
                if ($code === 200) {
                    $rawResultData = $result['data'] ?? [];
                    
                    // Normalize price options
                    $totalPrice = 0;
                    $currency = 'USD';
                    if (is_array($rawResultData)) {
                        $totalPrice = floatval($rawResultData['totalCost'] ?? ($rawResultData['pcbPrice'] ?? ($rawResultData['cost'] ?? 0)));
                        $currency = $rawResultData['currency'] ?? 'USD';
                    }

                    return [
                        'success' => true,
                        'source' => 'jlcpcb',
                        'code' => 200,
                        'message' => 'Quotation calculated successfully',
                        'fileKey' => $payload['fileKey'] ?? '',
                        'quotation' => [
                            'price' => $totalPrice,
                            'currency' => $currency,
                            'quantity' => $payload['pcbParam']['qty'] ?? 5,
                            'layers' => $payload['pcbParam']['layer'] ?? 4,
                            'delivery_time' => $payload['achieveDate'] ?? 48
                        ],
                        'data' => $rawResultData
                    ];
                }

                $errorMessage = $result['message'] ?? $this->getErrorMessageByCode($code);
                return [
                    'success' => false,
                    'source' => 'jlcpcb',
                    'code' => $code ?? $calcHttpCode,
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? null
                ];
            }

            if ($calcHttpCode < 200 || $calcHttpCode >= 300) {
                Log::error("JLCPCB API HTTP Failure", [
                    'status' => $calcHttpCode,
                    'body' => $calcResponseBody
                ]);
                return [
                    'success' => false,
                    'source' => 'jlcpcb',
                    'code' => $calcHttpCode,
                    'message' => 'JLCPCB API HTTP Error: ' . $calcHttpCode,
                    'raw_response' => $calcResponseBody
                ];
            }

            return [
                'success' => false,
                'source' => 'jlcpcb',
                'code' => $calcHttpCode,
                'message' => 'Unexpected API response format',
                'raw_response' => $calcResponseBody
            ];

        } catch (Exception $e) {
            Log::error("JLCPCB Exception: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new Exception("Error communicating with JLCPCB API: " . $e->getMessage());
        }
    }

    /**
     * Build standard payload combining user input and defaults
     */
    public function buildPayload(array $input): array
    {
        $defaultPcbParam = [
            'layer' => 2,
            'width' => 100,
            'length' => 100,
            'qty' => 5,
            'thickness' => 1.6,
            'pcbColor' => 0, // 0-green
            'surfaceFinish' => 0, // 0-HASL with lead
            'copperWeight' => 1, // 1 oz
            'insideCuprumThickness' => '0.5',
            'goldFinger' => 0, // 0-Not required
            'materialDetails' => 0, // 0-FR4 Standard Tg 140°C
            'panelFlag' => 0, // 0-Single PCB
            'panelByJLCPCB_X' => 0,
            'panelByJLCPCB_Y' => 0,
            'differentDesign' => 1,
            'flyingProbeTest' => 1, // 1-Sample test, 2-100% test
            'castellatedHoles' => 0,
            'orderDetailsRemark' => 'Quote calculated via Megabyte API',
            'cascadeStructure' => 0,
            'impedanceFlag' => 'no',
            'isAddCustomerCode' => 'nocode',
            'plateType' => 1, // 1-FR-4
            'autoConfirmProductionFile' => true,
            'markOnPcb' => 1, // 1-No marking
            'viaCovering' => 1, // 1-Tented
            'needTechnics' => 0,
            'edgeRounding' => false,
            'serviceConfigVos' => []
        ];

        $pcbParam = array_merge($defaultPcbParam, $input['pcbParam'] ?? []);

        return [
            'orderType' => $input['orderType'] ?? 1, // 1 (PCB), 2 (PCB + Stencil), 3 (Stencil)
            'pcbParam' => $pcbParam,
            'smtStencilParam' => $input['smtStencilParam'] ?? null,
            'achieveDate' => $input['achieveDate'] ?? 48,
            'country' => $input['country'] ?? 'IN',
            'postCode' => $input['postCode'] ?? '',
            'city' => $input['city'] ?? '',
            'fileKey' => $input['fileKey'] ?? '',
            'batchNum' => $input['batchNum'] ?? '',
            'shippingMethod' => $input['shippingMethod'] ?? ''
        ];
    }

    /**
     * Return default reference payload structure
     */
    public function getDefaultPayload(): array
    {
        return $this->buildPayload([]);
    }

    /**
     * Friendly error messages for standard JLCAPI response codes
     */
    protected function getErrorMessageByCode($code): string
    {
        $errors = [
            1000 => 'Forbidden IP (Your server IP is not whitelisted in JLCPAPI console)',
            1001 => 'Invalid Token – unable to retrieve relevant information',
            1002 => 'Too frequent request rate limit exceeded',
            1003 => 'JLCPCB Internal Server Error',
            1004 => 'Request Path Error',
            2000 => 'Incomplete Parameter',
            2001 => 'File Url is invalid',
            2002 => 'Exceed File Size limitation',
            2003 => 'Unsupported file name or format',
            2004 => 'orderType is null or invalid',
            2100 => 'PCB layer value error',
            2101 => 'PCB length format error',
            2102 => 'PCB width format error',
            2103 => 'PCB quantity parameter error',
            2104 => 'PCB thickness format error',
            2105 => 'PCB solder mask color format error',
            2106 => 'Surface finish format error',
            2107 => 'Copper weight format error',
            2108 => 'Gold finger format error',
            2109 => 'Material details format error',
            2125 => 'Board size limitation exceeded',
            2126 => 'Minimum board size limit error',
            2300 => 'Shipping method parameter error',
            2301 => 'Shipping method not available for destination country'
        ];

        return $errors[$code] ?? "JLCPCB API Error (Code: {$code})";
    }

    /**
     * List of ISO Country Codes for JLCPCB quotation shipping calculation
     */
    public function getCountryCodes(): array
    {
        return [
            ['code' => 'IN', 'name' => 'INDIA'],
            ['code' => 'US', 'name' => 'UNITED STATES OF AMERICA'],
            ['code' => 'GB', 'name' => 'UNITED KINGDOM'],
            ['code' => 'DE', 'name' => 'GERMANY'],
            ['code' => 'CA', 'name' => 'CANADA'],
            ['code' => 'AU', 'name' => 'AUSTRALIA'],
            ['code' => 'NL', 'name' => 'THE NETHERLANDS'],
            ['code' => 'FR', 'name' => 'FRANCE'],
            ['code' => 'IT', 'name' => 'ITALY'],
            ['code' => 'ES', 'name' => 'SPAIN'],
            ['code' => 'JP', 'name' => 'JAPAN'],
            ['code' => 'KR', 'name' => 'KOREA, REPUBLIC OF'],
            ['code' => 'SG', 'name' => 'SINGAPORE'],
            ['code' => 'AE', 'name' => 'UNITED ARAB EMIRATES'],
            ['code' => 'CN', 'name' => 'CHINA']
        ];
    }

    /**
     * Determine server public outbound IP address for JLCPCB IP Whitelisting
     */
    public function checkServerPublicIp(): array
    {
        $ipv4 = null;
        $ipv6 = null;

        try {
            $res4 = Http::timeout(5)->withOptions(['ipresolve' => CURL_IPRESOLVE_V4])->get('https://api.ipify.org?format=json');
            if ($res4->successful()) {
                $ipv4 = $res4->json('ip');
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch outbound IPv4: " . $e->getMessage());
        }

        try {
            $res6 = Http::timeout(5)->withOptions(['ipresolve' => CURL_IPRESOLVE_V6])->get('https://api64.ipify.org?format=json');
            if ($res6->successful()) {
                $ipv6 = $res6->json('ip');
            }
        } catch (\Exception $e) {
            // IPv6 might not be available
        }

        return [
            'outbound_ipv4' => $ipv4,
            'outbound_ipv6' => $ipv6,
            'recommended_whitelist_ip' => $ipv4 ?? $ipv6,
            'instructions' => 'Add the outbound_ipv4 address to the IP Whitelist inside your JLCPCB Open Platform Console (https://open.jlcpcb.com).'
        ];
    }

    /**
     * Get client IP of current HTTP request or CLI context
     */
    public function getRequestClientIp(): string
    {
        try {
            if (request()->hasHeader('X-Forwarded-For')) {
                $ips = explode(',', request()->header('X-Forwarded-For'));
                return trim($ips[0]);
            }
            return request()->ip() ?? (request()->server('REMOTE_ADDR') ?? '127.0.0.1');
        } catch (\Throwable $e) {
            return '127.0.0.1';
        }
    }

    /**
     * Get outbound public IP address of this server (cached 60 seconds)
     */
    public function getOutboundPublicIp(bool $forceRefresh = false): ?string
    {
        try {
            if ($forceRefresh) {
                \Illuminate\Support\Facades\Cache::forget('jlcpcb_outbound_ip');
            }
            return \Illuminate\Support\Facades\Cache::remember('jlcpcb_outbound_ip', 60, function () {
                $res = Http::timeout(3)->withOptions(['ipresolve' => CURL_IPRESOLVE_V4])->get('https://api.ipify.org?format=json');
                if ($res->successful()) {
                    return $res->json('ip');
                }
                return null;
            });
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format IP info header for logs
     */
    public function getLogIpString(): string
    {
        $clientIp = $this->getRequestClientIp();
        $outboundIp = $this->getOutboundPublicIp();
        return "Request Client IP: {$clientIp}" . ($outboundIp ? " | Outbound Public IP: {$outboundIp}" : "");
    }
}

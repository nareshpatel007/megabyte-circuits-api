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

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.jlcpcb.base_url', 'https://open.jlcpcb.com'), '/');
        $this->appId = config('services.jlcpcb.app_id');
        $this->accessKey = config('services.jlcpcb.access_key');
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
        if (empty($this->accessKey)) {
            throw new Exception("JLCPCB Access Key is not configured in environment (.env).");
        }

        $url = "{$this->baseUrl}/overseas/openapi/pcb/calculate";

        // Merge input with default structured parameters
        $payload = $this->buildPayload($input);

        Log::info("JLCPCB Calculate Request", ['url' => $url, 'payload' => $payload]);

        $authHeader = (str_starts_with(strtolower($this->accessKey), 'bearer ')) 
            ? $this->accessKey 
            : "Bearer {$this->accessKey}";

        $headers = [
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($url, $payload);

            $result = $response->json();
            Log::info("JLCPCB Calculate Response", ['status' => $response->status(), 'result' => $result]);

            if (is_array($result)) {
                $code = $result['code'] ?? null;
                if ($code === 200) {
                    return [
                        'success' => true,
                        'code' => 200,
                        'message' => 'Quotation calculated successfully',
                        'data' => $result['data'] ?? []
                    ];
                }

                $errorMessage = $result['message'] ?? $this->getErrorMessageByCode($code);
                return [
                    'success' => false,
                    'code' => $code ?? $response->status(),
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? null
                ];
            }

            if ($response->failed()) {
                Log::error("JLCPCB API HTTP Failure", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [
                    'success' => false,
                    'code' => $response->status(),
                    'message' => 'JLCPCB API HTTP Error: ' . $response->status(),
                    'raw_response' => $response->body()
                ];
            }

            return [
                'success' => false,
                'code' => $response->status(),
                'message' => 'Unexpected API response format',
                'raw_response' => $response->body()
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
}

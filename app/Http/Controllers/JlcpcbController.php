<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\JlcpcbService;
use Throwable;

class JlcpcbController extends Controller
{
    protected JlcpcbService $jlcpcbService;

    public function __construct(JlcpcbService $jlcpcbService)
    {
        $this->jlcpcbService = $jlcpcbService;
    }

    /**
     * Upload Gerber ZIP/RAR file to JLCPCB Open API
     * POST /api/jlcpcb/upload-gerber
     */
    public function uploadGerber(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:zip,rar,7z|max:20480',
                'fileName' => 'nullable|string'
            ]);

            $file = $request->file('file');
            $fileName = $request->input('fileName', $file->getClientOriginalName());

            $result = $this->jlcpcbService->uploadGerber($file, $fileName);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'code' => 200,
                    'message' => $result['message'],
                    'fileKey' => $result['fileKey'],
                    'data' => $result['data']
                ], 200);
            }

            return response()->json([
                'success' => false,
                'code' => $result['code'] ?? 400,
                'message' => $result['message'] ?? 'Failed to upload Gerber file to JLCPCB',
                'data' => null
            ], 400);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'code' => 500,
                'message' => 'Server Error: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate PCB Online Quotation via JLCPCB Open API
     * POST /api/jlcpcb/calculate
     */
    public function calculate(Request $request)
    {
        try {
            $input = $request->all();

            $result = $this->jlcpcbService->calculateQuotation($input);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'source' => $result['source'] ?? 'jlcpcb',
                    'code' => 200,
                    'message' => $result['message'],
                    'fileKey' => $result['fileKey'] ?? null,
                    'currency' => $result['currency'] ?? 'INR',
                    'quantity' => $result['quantity'] ?? null,
                    'layers' => $result['layers'] ?? null,
                    'pcb_price' => $result['pcb_price'] ?? null,
                    'shipping_charge' => $result['shipping_charge'] ?? null,
                    'subtotal' => $result['subtotal'] ?? null,
                    'gst_percentage' => $result['gst_percentage'] ?? null,
                    'gst_amount' => $result['gst_amount'] ?? null,
                    'final_total' => $result['final_total'] ?? null,
                    'base_inr' => $result['base_inr'] ?? null,
                    'dates' => $result['dates'] ?? [],
                    'quotation' => $result['quotation'] ?? null
                ], 200);
            }

            return response()->json([
                'success' => false,
                'source' => 'jlcpcb',
                'code' => $result['code'] ?? 400,
                'message' => $result['message'] ?? 'Failed to calculate JLCPCB quotation',
                'data' => $result['data'] ?? null,
                'raw_response' => $result['raw_response'] ?? null
            ], 400);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'source' => 'jlcpcb',
                'code' => 500,
                'message' => 'Server Error: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Return default reference payload structure for frontend quotation form
     * GET /api/jlcpcb/defaults
     */
    public function defaults()
    {
        return response()->json([
            'success' => true,
            'data' => $this->jlcpcbService->getDefaultPayload()
        ]);
    }

    /**
     * Return list of standard country codes for shipping
     * GET /api/jlcpcb/countries
     */
    public function countries()
    {
        return response()->json([
            'success' => true,
            'data' => $this->jlcpcbService->getCountryCodes()
        ]);
    }

    /**
     * Check server outbound public IP for JLCPCB whitelist configuration
     * GET /api/jlcpcb/check-ip
     */
    public function checkIp()
    {
        return response()->json([
            'success' => true,
            'data' => $this->jlcpcbService->checkServerPublicIp()
        ]);
    }
}

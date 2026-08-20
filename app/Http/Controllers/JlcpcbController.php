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
                    'code' => 200,
                    'message' => $result['message'],
                    'data' => $result['data']
                ], 200);
            }

            return response()->json([
                'success' => false,
                'code' => $result['code'] ?? 400,
                'message' => $result['message'] ?? 'Failed to calculate JLCPCB quotation',
                'data' => $result['data'] ?? null
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
}

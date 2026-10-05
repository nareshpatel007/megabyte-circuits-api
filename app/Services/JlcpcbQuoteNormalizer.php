<?php

namespace App\Services;

class JlcpcbQuoteNormalizer
{
    /**
     * Normalize raw JLCPCB quotation response into a standardized quote object.
     *
     * @param array $rawResultData  Raw data object from JLCPCB calculate response
     * @param array $requestPayload Original request payload sent to JLCPCB (optional)
     * @param array $options        Additional overrides/options (optional)
     * @return array
     */
    public function normalize(array $rawResultData, array $requestPayload = [], array $options = []): array
    {
        // Handle case where rawResultData is wrapped under 'data'
        $data = isset($rawResultData['data']) && is_array($rawResultData['data']) 
            ? $rawResultData['data'] 
            : $rawResultData;

        $cost = $data['pcbCostInfo'] ?? [];
        if (!is_array($cost)) {
            $cost = [];
        }

        // 1. Engineering Fee
        $projectFee = round((float)($cost['projectFee'] ?? 0), 2);

        // 2. Via Covering Fee
        $viaCoveringMoney = round((float)($cost['viaCoveringMoney'] ?? 0), 2);

        // 3. Surface Finish Fee
        // Check if request payload specifies HASL with lead (code 0 / "HASL(with lead)" / "HASL(Leaded)" / "HASL")
        $rawSf = $requestPayload['pcbParam']['surfaceFinish'] 
            ?? ($requestPayload['surfaceFinish'] 
            ?? ($options['surfaceFinish'] ?? null));

        $isHaslWithLead = false;
        if ($rawSf !== null) {
            if (is_numeric($rawSf) && (int)$rawSf === 0) {
                $isHaslWithLead = true;
            } elseif (is_string($rawSf)) {
                $sfClean = strtolower(trim($rawSf));
                if (str_contains($sfClean, 'hasl') && (str_contains($sfClean, 'lead') && !str_contains($sfClean, 'free')) || $sfClean === 'hasl') {
                    $isHaslWithLead = true;
                }
            }
        }
        if (!empty($options['isHaslWithLead'])) {
            $isHaslWithLead = true;
        }

        $adornPutFee = round((float)($cost['adornPutFee'] ?? 0), 2);
        $surfaceFinishAmount = $isHaslWithLead ? 0.00 : $adornPutFee;

        // 4. Film Fee
        $fillFee = round((float)($cost['fillFee'] ?? 0), 2);

        // 5. Board Fee (stencilFee in JLCPCB API represents board fabrication fee)
        $stencilFee = round((float)($cost['stencilFee'] ?? ($cost['originStencilMoney'] ?? 0)), 2);

        // 6. Confirm Production File Fee (from serviceConfigFeeInfo or options)
        $confirmFileFee = 0.00;
        $hasCpfConfig = false;

        $serviceConfigFeeInfo = $data['serviceConfigFeeInfo'] ?? [];
        if (is_array($serviceConfigFeeInfo)) {
            foreach ($serviceConfigFeeInfo as $service) {
                if (is_array($service) && ($service['serviceConfigCode'] ?? '') === 'CPF') {
                    $confirmFileFee = round((float)($service['serviceFee'] ?? 0), 2);
                    $hasCpfConfig = true;
                    break;
                }
            }
        }

        // Fallback to explicit options if serviceConfigFeeInfo did not contain it
        if (!$hasCpfConfig && isset($options['confirmProductionFileFee'])) {
            $confirmFileFee = round((float)$options['confirmProductionFileFee'], 2);
            $hasCpfConfig = true;
        }

        // Determine if Confirm Production File was requested in payload
        $isCpfRequested = false;
        if (!empty($requestPayload['pcbParam']['serviceConfigVos']) && is_array($requestPayload['pcbParam']['serviceConfigVos'])) {
            foreach ($requestPayload['pcbParam']['serviceConfigVos'] as $vo) {
                if (($vo['serviceConfigCode'] ?? '') === 'CPF' && strtolower((string)($vo['configOptionShow'] ?? '')) === 'yes') {
                    $isCpfRequested = true;
                    break;
                }
            }
        }
        if (!$isCpfRequested) {
            $reqCpf = $requestPayload['confirmFile'] 
                ?? ($requestPayload['confirm_file'] 
                ?? ($requestPayload['pcbParam']['confirmFile'] ?? null));
            if ($reqCpf && strtolower(trim((string)$reqCpf)) === 'yes') {
                $isCpfRequested = true;
            }
        }

        // 7. Assemble Standard PCB Charges
        $pcbCharges = [
            [
                'code' => 'engineering_fee',
                'label' => 'Engineering fee',
                'amount' => $projectFee
            ],
            [
                'code' => 'via_covering',
                'label' => 'Via Covering',
                'amount' => $viaCoveringMoney
            ],
            [
                'code' => 'surface_finish',
                'label' => 'Surface Finish',
                'amount' => $surfaceFinishAmount
            ],
            [
                'code' => 'film',
                'label' => 'Film',
                'amount' => $fillFee
            ],
            [
                'code' => 'board',
                'label' => 'Board',
                'amount' => $stencilFee
            ]
        ];

        // Include Confirm Production file if present in serviceConfigFeeInfo, requested, or fee > 0
        if ($hasCpfConfig || $isCpfRequested || $confirmFileFee > 0) {
            $pcbCharges[] = [
                'code' => 'confirm_production_file',
                'label' => 'Confirm Production file',
                'amount' => $confirmFileFee
            ];
        }

        // Include any additional process fees from pcbCostInfo if > 0
        $optionalFees = [
            'goldThicknessMoney' => ['code' => 'gold_thickness', 'label' => 'Gold Thickness'],
            'edgeGrindingMoney' => ['code' => 'edge_grinding', 'label' => 'Edge Plating'],
            'specialProcessMoney' => ['code' => 'special_process', 'label' => 'Special Process'],
            'halfHoleFee' => ['code' => 'castellated_holes', 'label' => 'Castellated Holes'],
            'insideCuprumThicknessFee' => ['code' => 'inner_copper', 'label' => 'Inner Copper Thickness'],
            'cuprumThicknessFee' => ['code' => 'outer_copper', 'label' => 'Outer Copper Thickness'],
            'noCodeMoney' => ['code' => 'no_code', 'label' => 'Specify Order Number'],
        ];

        foreach ($optionalFees as $feeKey => $meta) {
            $amt = round((float)($cost[$feeKey] ?? 0), 2);
            if ($amt > 0) {
                $pcbCharges[] = [
                    'code' => $meta['code'],
                    'label' => $meta['label'],
                    'amount' => $amt
                ];
            }
        }

        // Include any other active service config fees > 0
        if (is_array($serviceConfigFeeInfo)) {
            foreach ($serviceConfigFeeInfo as $service) {
                if (is_array($service)) {
                    $scCode = (string)($service['serviceConfigCode'] ?? '');
                    $scAmt = round((float)($service['serviceFee'] ?? 0), 2);
                    // Skip CPF (handled above) and PPBP (standard paper)
                    if ($scCode !== 'CPF' && $scCode !== 'PPBP' && $scAmt > 0) {
                        $pcbCharges[] = [
                            'code' => strtolower($scCode),
                            'label' => (string)($service['serviceConfigShow'] ?? $scCode),
                            'amount' => $scAmt
                        ];
                    }
                }
            }
        }

        // 8. Calculate Base PCB Price (Sum of normalized customer-facing charges)
        $basePcbPrice = round((float)array_sum(array_column($pcbCharges, 'amount')), 2);

        // Fallback: If basePcbPrice computed to 0, attempt fallback
        if ($basePcbPrice <= 0.0) {
            $totalFee = round((float)($cost['totalFee'] ?? ($data['priceWithoutFreight'] ?? 0)), 2);
            $testsFee = round((float)($cost['testsFee'] ?? 0), 2);
            if ($totalFee > 0.0) {
                // If testsFee is present, subtract it
                $basePcbPrice = round(max(0.0, $totalFee - $testsFee), 2);
            }
        }

        // 9. Build Time Options
        $buildTimeOptions = [];
        $rawAchieveList = $data['achieveDateList'] ?? [];
        if (is_array($rawAchieveList) && count($rawAchieveList) > 0) {
            foreach ($rawAchieveList as $achieve) {
                if (is_array($achieve)) {
                    $buildTimeOptions[] = [
                        'name' => (string)($achieve['achieveName'] ?? (($achieve['achieveDate'] ?? '') . ' hours')),
                        'price' => round((float)($achieve['achievePrice'] ?? 0), 2),
                        'checked' => ($achieve['achieveChecked'] ?? '') === 'checked',
                        'hours' => (int)($achieve['achieveDate'] ?? 48)
                    ];
                }
            }
        } else {
            $buildTimeOptions = [
                ['name' => '5-6 days', 'price' => 0.00, 'checked' => true, 'hours' => 120],
                ['name' => '3-4 days', 'price' => 0.00, 'checked' => false, 'hours' => 72]
            ];
        }

        // 10. Shipping Options
        $shippingOptions = [];
        $rawShipList = $data['shipList'] ?? [];
        if (is_array($rawShipList)) {
            foreach ($rawShipList as $ship) {
                if (is_array($ship) && isset($ship['cost'])) {
                    $shippingOptions[] = [
                        'code' => (string)($ship['options'] ?? ''),
                        'name' => (string)($ship['showOptions'] ?? ($ship['options'] ?? 'Standard Shipping')),
                        'price' => round((float)$ship['cost'], 2),
                        'deliveryDays' => (string)($ship['day'] ?? '')
                    ];
                }
            }
        }

        // 11. Provider Totals & Weights
        $providerTotalFee = round((float)($cost['totalFee'] ?? ($data['priceWithoutFreight'] ?? 0)), 2);
        $providerPriceWithoutFreight = round((float)($data['priceWithoutFreight'] ?? ($cost['totalFee'] ?? 0)), 2);

        $weight = null;
        if (isset($cost['weight']) && (float)$cost['weight'] > 0) {
            $weight = round((float)$cost['weight'], 4);
        } elseif (isset($data['weight']) && (float)$data['weight'] > 0) {
            $w = (float)$data['weight'];
            $weight = $w > 10 ? round($w / 1000.0, 4) : round($w, 4);
        } elseif (isset($data['orderTotalWeight']) && (float)$data['orderTotalWeight'] > 0) {
            $w = (float)$data['orderTotalWeight'];
            $weight = $w > 10 ? round($w / 1000.0, 4) : round($w, 4);
        }

        $chargeWeight = round((float)($cost['chargeWeight'] ?? ($data['chargeWeight'] ?? 0)), 4);

        return [
            'pcbCharges' => $pcbCharges,
            'basePcbPrice' => $basePcbPrice,
            'buildTimeOptions' => $buildTimeOptions,
            'shippingOptions' => $shippingOptions,
            'providerTotalFee' => $providerTotalFee,
            'providerPriceWithoutFreight' => $providerPriceWithoutFreight,
            'weight' => $weight,
            'chargeWeight' => $chargeWeight
        ];
    }
}

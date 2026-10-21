<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class OrderNumberService
{
    /**
     * Generate a unique, atomic, sequential order number for a given series ('M' or 'JL').
     *
     * Series M: M0001, M0002, M0003... (Normal / Internal PCB orders, 4-digit padding)
     * Series JL: JL0001, JL0002, JL0003... (JLCPCB PCB orders, 4-digit padding)
     *
     * @param string $series 'M' or 'JL' (or legacy 'J')
     * @param int|null $paddingLength Custom padding length (Defaults: 4 for 'M', 4 for 'JL')
     * @return string Formatted order number (e.g. M0001 or JL0001)
     */
    public static function generateOrderNumber(string $series = 'M', ?int $paddingLength = null): string
    {
        $series = strtoupper(trim($series));
        if ($series === 'J') {
            $series = 'J';
            if ($paddingLength === null) {
                $paddingLength = 4;
            }
        } elseif ($series === 'JL') {
            $series = 'JL';
            if ($paddingLength === null) {
                $paddingLength = 4;
            }
        } else {
            $series = 'M';
            if ($paddingLength === null) {
                $paddingLength = 4;
            }
        }

        return DB::transaction(function () use ($series, $paddingLength) {
            // Lock order_sequences row for update to prevent concurrent duplicate generation
            $sequence = DB::table('order_sequences')
                ->where('series', $series)
                ->lockForUpdate()
                ->first();

            // Determine highest numeric suffix currently present in pcb_orders for this series
            $highestPcbOrderNum = 0;
            if ($series === 'M') {
                // If sequence counter exists, use it authoritatively without scanning historical orders.
                // Only scan active records if the sequence row is completely missing.
                if (!$sequence) {
                    $orders = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', 'M%')
                        ->where('order_number', 'NOT LIKE', '%-%')
                        ->whereNull('deleted_at')
                        ->get(['order_number']);
                    foreach ($orders as $ord) {
                        if (preg_match('/^M([0-9]+)$/', (string)$ord->order_number, $matches)) {
                            $num = (int)$matches[1];
                            if ($num > $highestPcbOrderNum) {
                                $highestPcbOrderNum = $num;
                            }
                        }
                    }
                }
            } else if ($series === 'JL') {
                $orders = DB::table('pcb_orders')
                    ->where('order_number', 'LIKE', 'JL%')
                    ->where('order_number', 'NOT LIKE', '%-%')
                    ->get(['order_number']);
                foreach ($orders as $ord) {
                    $num = (int) preg_replace('/[^0-9]/', '', (string)$ord->order_number);
                    if ($num > $highestPcbOrderNum) {
                        $highestPcbOrderNum = $num;
                    }
                }
            } else if ($series === 'J') {
                $orders = DB::table('pcb_orders')
                    ->where('order_number', 'LIKE', 'J%')
                    ->where('order_number', 'NOT LIKE', 'JL%')
                    ->where('order_number', 'NOT LIKE', '%-%')
                    ->get(['order_number']);
                foreach ($orders as $ord) {
                    $num = (int) preg_replace('/[^0-9]/', '', (string)$ord->order_number);
                    if ($num > $highestPcbOrderNum) {
                        $highestPcbOrderNum = $num;
                    }
                }
            }

            $currentSequenceLast = $sequence ? (int)$sequence->last_number : 0;
            // Self-healing counter reconciliation: Never regress backwards below historical orders
            $reconciledLast = max($currentSequenceLast, $highestPcbOrderNum);
            $candidateNumber = $reconciledLast + 1;

            // Check if candidate order number already exists in pcb_orders and auto-increment if found
            while (true) {
                $paddedCandidate   = $series . str_pad($candidateNumber, $paddingLength, '0', STR_PAD_LEFT);
                $unpaddedCandidate = $series . $candidateNumber;

                $exists = DB::table('pcb_orders')
                    ->where(function ($q) use ($paddedCandidate, $unpaddedCandidate) {
                        $q->where('order_number', $paddedCandidate)
                          ->orWhere('order_number', $unpaddedCandidate);
                    })
                    ->exists();

                if (!$exists) {
                    break;
                }

                $candidateNumber++;
            }

            if ($sequence) {
                DB::table('order_sequences')
                    ->where('series', $series)
                    ->update([
                        'last_number' => $candidateNumber,
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);
            } else {
                DB::table('order_sequences')->insert([
                    'series'      => $series,
                    'last_number' => $candidateNumber,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            return $series . str_pad($candidateNumber, $paddingLength, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Determine sequence series ('M' or 'JL') and generate order number for given order type.
     *
     * @param string|null $orderType 'jlcpcb' / 'normal' or quotation_source 'jlcpcb' / 'internal'
     * @return string
     */
    public static function generateForType(?string $orderType): string
    {
        $normalizedType = strtolower(trim((string)$orderType));
        $series = ($normalizedType === 'jlcpcb') ? 'JL' : 'M';
        return self::generateOrderNumber($series);
    }
}


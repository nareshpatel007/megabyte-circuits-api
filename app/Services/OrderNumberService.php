<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class OrderNumberService
{
    /**
     * Generate a unique, atomic, sequential order number for a given series ('M' or 'JL').
     *
     * Series M: M00001, M00002, M00003... (Normal / Internal PCB orders, 5-digit padding)
     * Series JL: JL0001, JL0002, JL0003... (JLCPCB PCB orders, 4-digit padding)
     *
     * @param string $series 'M' or 'JL' (or legacy 'J')
     * @param int|null $paddingLength Custom padding length (Defaults: 5 for 'M', 4 for 'JL')
     * @return string Formatted order number (e.g. M00001 or JL0001)
     */
    public static function generateOrderNumber(string $series = 'M', ?int $paddingLength = null): string
    {
        $series = strtoupper(trim($series));
        if ($series === 'J' || $series === 'JL') {
            $series = 'JL';
            if ($paddingLength === null) {
                $paddingLength = 4;
            }
        } else {
            $series = 'M';
            if ($paddingLength === null) {
                $paddingLength = 5;
            }
        }

        return DB::transaction(function () use ($series, $paddingLength) {
            // Lock order_sequences row for update to prevent concurrent duplicate generation
            $sequence = DB::table('order_sequences')
                ->where('series', $series)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                // Initialize sequence if not present in table
                $initialNumber = 0;
                if ($series === 'M') {
                    $lastOrder = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', 'M%')
                        ->where('order_number', 'NOT LIKE', '%-%')
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($lastOrder && !empty($lastOrder->order_number)) {
                        $num = (int) preg_replace('/[^0-9]/', '', $lastOrder->order_number);
                        $maxId = DB::table('pcb_orders')->max('id') ?? 0;
                        $initialNumber = max($num, $maxId);
                    } else {
                        $initialNumber = DB::table('pcb_orders')->max('id') ?? 0;
                    }
                } else if ($series === 'JL') {
                    $lastOrder = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', 'JL%')
                        ->where('order_number', 'NOT LIKE', '%-%')
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($lastOrder && !empty($lastOrder->order_number)) {
                        $initialNumber = (int) preg_replace('/[^0-9]/', '', $lastOrder->order_number);
                    } else {
                        $initialNumber = 0;
                    }
                }

                DB::table('order_sequences')->insert([
                    'series' => $series,
                    'last_number' => $initialNumber,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $sequence = DB::table('order_sequences')
                    ->where('series', $series)
                    ->lockForUpdate()
                    ->first();
            }

            $candidateNumber = ((int)$sequence->last_number) + 1;

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

            DB::table('order_sequences')
                ->where('series', $series)
                ->update([
                    'last_number' => $candidateNumber,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);

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


<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class OrderNumberService
{
    /**
     * Generate a unique, atomic, sequential order number for a given series ('M' or 'J').
     *
     * Series M: M00001, M00002, M00003... (Normal / Internal PCB orders)
     * Series J: J00001, J00002, J00003... (JLCPCB PCB orders)
     *
     * @param string $series 'M' or 'J'
     * @param int $paddingLength Default 5
     * @return string Formatted order number (e.g. M00001 or J00001)
     */
    public static function generateOrderNumber(string $series = 'M', int $paddingLength = 5): string
    {
        $series = strtoupper(trim($series));
        if (!in_array($series, ['M', 'J'])) {
            $series = 'M';
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
                } else if ($series === 'J') {
                    $lastOrder = DB::table('pcb_orders')
                        ->where('order_number', 'LIKE', 'J%')
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

            $nextNumber = ((int)$sequence->last_number) + 1;

            DB::table('order_sequences')
                ->where('series', $series)
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return $series . str_pad($nextNumber, $paddingLength, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Determine sequence series ('M' or 'J') and generate order number for given order type.
     *
     * @param string|null $orderType 'jlcpcb' / 'normal' or quotation_source 'jlcpcb' / 'internal'
     * @return string
     */
    public static function generateForType(?string $orderType): string
    {
        $normalizedType = strtolower(trim((string)$orderType));
        $series = ($normalizedType === 'jlcpcb') ? 'J' : 'M';
        return self::generateOrderNumber($series);
    }
}

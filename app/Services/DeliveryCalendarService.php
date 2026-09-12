<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DeliveryCalendarService
{
    /**
     * Check if a given date is Sunday.
     */
    public static function isSunday($date): bool
    {
        $carbonDate = $date instanceof Carbon ? $date : Carbon::parse($date);
        return $carbonDate->dayOfWeek === Carbon::SUNDAY;
    }

    /**
     * Fetch active holidays within date range.
     */
    public static function getHolidaysInRange($startDate, $endDate): Collection
    {
        $start = $startDate instanceof Carbon ? $startDate->format('Y-m-d') : Carbon::parse($startDate)->format('Y-m-d');
        $end = $endDate instanceof Carbon ? $endDate->format('Y-m-d') : Carbon::parse($endDate)->format('Y-m-d');

        return Holiday::where('is_active', true)
            ->whereBetween('date', [$start, $end])
            ->get();
    }

    /**
     * Check if a given date matches an active holiday in a collection or database.
     */
    public static function isHoliday($date, ?Collection $holidays = null): ?Holiday
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');

        if ($holidays === null) {
            return Holiday::where('is_active', true)
                ->where('date', $dateStr)
                ->first();
        }

        return $holidays->first(function ($holiday) use ($dateStr) {
            $hDate = $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string)$holiday->date;
            return $hDate === $dateStr;
        });
    }

    /**
     * Determine if a date is a valid delivery day (not Sunday and not an active holiday).
     */
    public static function isDeliveryDate($date, ?Collection $holidays = null): bool
    {
        if (self::isSunday($date)) {
            return false;
        }

        if (self::isHoliday($date, $holidays)) {
            return false;
        }

        return true;
    }

    /**
     * Calculate delivery date after N working/delivery days from start date.
     */
    public static function addDeliveryDays($startDate, int $days, ?Collection $holidays = null): Carbon
    {
        $current = $startDate instanceof Carbon ? $startDate->copy() : Carbon::parse($startDate);
        $added = 0;

        while ($added < $days) {
            $current->addDay();
            if (self::isDeliveryDate($current, $holidays)) {
                $added++;
            }
        }

        return $current;
    }

    /**
     * Validate if a selected delivery date string is acceptable for order placement.
     */
    public static function validateDeliveryDate($date): array
    {
        if (!$date) {
            return ['valid' => true, 'reason' => null];
        }

        try {
            $carbonDate = Carbon::parse($date);
            $formattedDate = $carbonDate->format('Y-m-d');

            if ($carbonDate->isPast() && !$carbonDate->isToday()) {
                return [
                    'valid' => false,
                    'reason' => 'The selected delivery date is in the past. Please select a valid future delivery date.'
                ];
            }

            if (self::isSunday($carbonDate)) {
                return [
                    'valid' => false,
                    'reason' => 'The selected delivery date is unavailable because Sunday is not a delivery day.'
                ];
            }

            $holiday = self::isHoliday($formattedDate);
            if ($holiday) {
                return [
                    'valid' => false,
                    'reason' => "The selected delivery date is unavailable because it is a holiday ({$holiday->name}). Please select another delivery date."
                ];
            }

            return ['valid' => true, 'reason' => null];
        } catch (\Throwable $e) {
            return ['valid' => false, 'reason' => 'Invalid delivery date format.'];
        }
    }
}

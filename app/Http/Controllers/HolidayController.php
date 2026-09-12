<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Services\DeliveryCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HolidayController extends Controller
{
    /**
     * Admin: List all holidays with filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        try {
            $query = Holiday::query();

            // Search by name
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where('name', 'LIKE', "%{$search}%");
            }

            // Filter by year
            if ($request->filled('year')) {
                $year = (int)$request->input('year');
                $query->whereYear('date', $year);
            }

            // Filter by status (active/inactive)
            if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
                $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
                $query->where('is_active', $isActive);
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'date');
            $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
            $query->orderBy($sortBy, $sortOrder);

            $perPage = (int)$request->input('per_page', 15);
            $holidays = $query->paginate($perPage);

            // Transform data for frontend response
            $formattedData = $holidays->getCollection()->map(function ($item) {
                $carbonDate = Carbon::parse($item->date);
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'date' => $carbonDate->format('Y-m-d'),
                    'formatted_date' => $carbonDate->format('d M Y'),
                    'day_of_week' => $carbonDate->format('l'),
                    'description' => $item->description,
                    'is_active' => (bool)$item->is_active,
                    'created_by' => $item->created_by,
                    'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
                    'updated_at' => $item->updated_at ? $item->updated_at->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'meta' => [
                    'current_page' => $holidays->currentPage(),
                    'last_page' => $holidays->lastPage(),
                    'per_page' => $holidays->perPage(),
                    'total' => $holidays->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch holidays: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Create a new holiday.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $formattedDate = Carbon::parse($request->input('date'))->format('Y-m-d');
            $isActive = $request->has('is_active') ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN) : true;

            // Prevent duplicate holiday entries for the same date if active
            $existing = Holiday::where('date', $formattedDate)->where('is_active', true)->first();
            if ($existing && $isActive) {
                return response()->json([
                    'success' => false,
                    'message' => "An active holiday ('{$existing->name}') is already configured for date {$formattedDate}."
                ], 422);
            }

            $adminUser = $request->attributes->get('admin');
            $adminId = $adminUser ? $adminUser->id : null;

            $holiday = Holiday::create([
                'name' => trim($request->input('name')),
                'date' => $formattedDate,
                'description' => $request->input('description'),
                'is_active' => $isActive,
                'created_by' => $adminId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Holiday created successfully',
                'holiday' => $holiday
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create holiday: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get a single holiday detail.
     */
    public function show($id)
    {
        try {
            $holiday = Holiday::findOrFail($id);
            return response()->json([
                'success' => true,
                'holiday' => $holiday
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found'
            ], 404);
        }
    }

    /**
     * Admin: Update an existing holiday.
     */
    public function update(Request $request, $id)
    {
        try {
            $holiday = Holiday::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'date' => 'required|date',
                'is_active' => 'nullable|boolean',
                'description' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $formattedDate = Carbon::parse($request->input('date'))->format('Y-m-d');
            $isActive = $request->has('is_active') ? filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN) : (bool)$holiday->is_active;

            // Check if updating to a date that conflicts with another active holiday
            $existing = Holiday::where('date', $formattedDate)
                ->where('id', '!=', $holiday->id)
                ->where('is_active', true)
                ->first();

            if ($existing && $isActive) {
                return response()->json([
                    'success' => false,
                    'message' => "Another active holiday ('{$existing->name}') is already configured for date {$formattedDate}."
                ], 422);
            }

            $holiday->update([
                'name' => trim($request->input('name')),
                'date' => $formattedDate,
                'description' => $request->input('description'),
                'is_active' => $isActive
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Holiday updated successfully',
                'holiday' => $holiday
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update holiday: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Delete (soft delete) a holiday.
     */
    public function destroy($id)
    {
        try {
            $holiday = Holiday::findOrFail($id);
            $holiday->delete();

            return response()->json([
                'success' => true,
                'message' => 'Holiday deleted successfully'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete holiday: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Toggle active status of a holiday.
     */
    public function toggleStatus($id)
    {
        try {
            $holiday = Holiday::findOrFail($id);
            $newStatus = !$holiday->is_active;

            if ($newStatus) {
                // Verify no other active holiday exists on the date
                $existing = Holiday::where('date', $holiday->date)
                    ->where('id', '!=', $holiday->id)
                    ->where('is_active', true)
                    ->first();
                if ($existing) {
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot activate holiday. Another active holiday ('{$existing->name}') already exists for date {$holiday->date}."
                    ], 422);
                }
            }

            $holiday->update(['is_active' => $newStatus]);

            return response()->json([
                'success' => true,
                'message' => 'Holiday status updated successfully',
                'is_active' => $holiday->is_active
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle holiday status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Customer Public Endpoint: Get active holidays for a given date range.
     */
    public function getPublicHolidays(Request $request)
    {
        try {
            $startDate = $request->input('start_date', Carbon::today()->format('Y-m-d'));
            $endDate = $request->input('end_date', Carbon::today()->addDays(60)->format('Y-m-d'));

            $holidays = DeliveryCalendarService::getHolidaysInRange($startDate, $endDate);

            $formatted = $holidays->map(function ($h) {
                $carbonDate = Carbon::parse($h->date);
                return [
                    'id' => $h->id,
                    'date' => $carbonDate->format('Y-m-d'),
                    'name' => $h->name,
                    'day_of_week' => $carbonDate->format('l'),
                    'description' => $h->description
                ];
            });

            return response()->json([
                'success' => true,
                'holidays' => $formatted
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery holidays: ' . $e->getMessage()
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Status;
use Illuminate\Support\Str;

class StatusController extends Controller
{
    // List all statuses
    public function index()
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses')) {
                $statuses = Status::orderBy('sort_order', 'asc')->get();
            } else if (\Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                $statuses = \Illuminate\Support\Facades\DB::table('pcb_statuses')->orderBy('sort_order', 'asc')->get();
            } else if (\Illuminate\Support\Facades\Schema::hasTable('statuses')) {
                $statuses = \Illuminate\Support\Facades\DB::table('statuses')->orderBy('sort_order', 'asc')->get();
            } else {
                $statuses = [
                    ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#10b981'],
                    ['id' => 2, 'name' => 'CAM Engineering', 'slug' => 'cam-engineering', 'color' => '#3b82f6'],
                    ['id' => 3, 'name' => 'In Production', 'slug' => 'in-production', 'color' => '#f59e0b'],
                    ['id' => 4, 'name' => 'Ready to Ship', 'slug' => 'ready-to-ship', 'color' => '#8b5cf6'],
                    ['id' => 5, 'name' => 'Completed', 'slug' => 'completed', 'color' => '#10b981'],
                ];
            }
            return response()->json([
                'status' => true,
                'data' => $statuses
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => true,
                'data' => []
            ]);
        }
    }

    // Store new status
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $slug = Str::slug($request->name);
        $count = Status::where('slug', $slug)->count();
        if ($count > 0) {
            $slug = $slug . '-' . time();
        }

        $maxSort = Status::max('sort_order') ?? 0;

        $status = Status::create([
            'name' => $request->name,
            'slug' => $slug,
            'sort_order' => $request->sort_order ?? ($maxSort + 1),
            'color' => $request->color ?? '#10b981',
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Status created successfully',
            'data' => $status
        ], 201);
    }

    // Update status
    public function update(Request $request, $id)
    {
        $status = Status::find($id);

        if (!$status) {
            return response()->json([
                'status' => false,
                'message' => 'Status not found'
            ], 404);
        }

        if ($request->has('name')) {
            $status->name = $request->name;
            $status->slug = Str::slug($request->name);
        }

        if ($request->has('sort_order')) {
            $status->sort_order = $request->sort_order;
        }

        if ($request->has('color')) {
            $status->color = $request->color;
        }

        if ($request->has('is_active')) {
            $status->is_active = $request->is_active;
        }

        $status->save();

        return response()->json([
            'status' => true,
            'message' => 'Status updated successfully',
            'data' => $status
        ]);
    }

    // Delete status
    public function destroy($id)
    {
        $status = Status::find($id);

        if (!$status) {
            return response()->json([
                'status' => false,
                'message' => 'Status not found'
            ], 404);
        }

        $status->delete();

        return response()->json([
            'status' => true,
            'message' => 'Status deleted successfully'
        ]);
    }
}

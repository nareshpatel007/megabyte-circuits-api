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
        $statuses = Status::orderBy('sort_order', 'asc')->get();
        return response()->json([
            'status' => true,
            'data' => $statuses
        ]);
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

<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileNotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');

            if (Schema::hasTable('notifications')) {
                $notifications = DB::table('notifications')
                    ->where('user_id', $adminId)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($n) {
                        return [
                            'id' => (string)$n->id,
                            'type' => $n->type ?? 'assignment',
                            'title' => $n->title ?? 'Production Notice',
                            'detail' => $n->detail ?? $n->message ?? '',
                            'time' => $n->created_at ? date('h:i A', strtotime($n->created_at)) : 'Just now',
                            'unread' => (bool)!$n->read
                        ];
                    });

                return response()->json([
                    'success' => true,
                    'data' => $notifications
                ]);
            }

            // Fallback dynamic notifications
            $notifications = [
                ['id' => 'n1', 'type' => 'assignment', 'title' => 'New job assigned', 'detail' => 'M4802 is ready for your Traveler stage.', 'time' => '12 min ago', 'unread' => true],
                ['id' => 'n2', 'type' => 'overdue', 'title' => 'Job due today', 'detail' => 'M4805 needs attention before dispatch.', 'time' => '1 hr ago', 'unread' => true],
                ['id' => 'n3', 'type' => 'inventory', 'title' => 'Low inventory warning', 'detail' => 'CAP-100UF is below minimum threshold.', 'time' => '3 hrs ago', 'unread' => true],
                ['id' => 'n4', 'type' => 'status', 'title' => 'Status changed', 'detail' => 'M1550-22 moved to Final QC stage.', 'time' => 'Yesterday', 'unread' => false],
                ['id' => 'n5', 'type' => 'announcement', 'title' => 'Shift handover notice', 'detail' => 'Complete your department handover before 6 PM.', 'time' => 'Yesterday', 'unread' => false],
            ];

            return response()->json([
                'success' => true,
                'data' => $notifications
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')
                    ->where('id', $id)
                    ->where('user_id', $adminId)
                    ->update(['read' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function markAllAsRead(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')
                    ->where('user_id', $adminId)
                    ->update(['read' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}

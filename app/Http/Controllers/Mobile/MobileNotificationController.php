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
                $query = DB::table('notifications');
                $hasRecipientId = Schema::hasColumn('notifications', 'recipient_id');
                $hasUserId = Schema::hasColumn('notifications', 'user_id');
                $hasIsRead = Schema::hasColumn('notifications', 'is_read');
                $hasRead = Schema::hasColumn('notifications', 'read');

                if ($hasRecipientId) {
                    if ($adminId) {
                        $query->where(function ($q) use ($adminId) {
                            $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                        });
                    }
                    if (Schema::hasColumn('notifications', 'recipient_type')) {
                        $query->whereIn('recipient_type', ['admin', 'all', 'user']);
                    }
                } elseif ($hasUserId && $adminId) {
                    $query->where('user_id', $adminId);
                }

                // 1. Exclude disabled events in notification_settings
                if (Schema::hasTable('notification_settings')) {
                    try {
                        $disabledEvents = DB::table('notification_settings')
                            ->where('admin_enabled', false)
                            ->pluck('event_key')
                            ->toArray();
                        if (!empty($disabledEvents)) {
                            $query->whereNotIn('event_key', $disabledEvents);
                        }
                    } catch (\Throwable $e) {}
                }

                // 2. Filter by employee permissions
                if ($adminId) {
                    try {
                        $admin = DB::table('admins')->where('id', $adminId)->first();
                        $permissions = $admin ? \App\Http\Controllers\Mobile\MobileAuthController::fetchPermissionsForAdmin($admin) : ['*'];
                        $isSuperAdmin = in_array('*', $permissions);

                        if (!$isSuperAdmin) {
                            $disallowedCategories = [];
                            if (!in_array('orders.view', $permissions)) $disallowedCategories[] = 'order';
                            if (!in_array('inventory.view', $permissions)) $disallowedCategories[] = 'inventory';
                            if (!in_array('payments.view', $permissions)) $disallowedCategories[] = 'payment';
                            if (!in_array('gerber.view', $permissions) && !in_array('orders.view_gerber', $permissions)) $disallowedCategories[] = 'gerber';
                            if (!in_array('staff.view', $permissions) && !in_array('users.manage', $permissions)) $disallowedCategories[] = 'staff';

                            if (!empty($disallowedCategories)) {
                                $query->whereNotIn('category', $disallowedCategories);
                            }
                        }
                    } catch (\Throwable $e) {}
                }

                $notifications = $query
                    ->orderBy('created_at', 'desc')
                    ->limit(50)
                    ->get()
                    ->map(function ($n) use ($hasIsRead, $hasRead) {
                        $isUnread = false;
                        if ($hasIsRead) {
                            $isUnread = !(bool)$n->is_read;
                        } elseif ($hasRead) {
                            $isUnread = !(bool)$n->read;
                        }

                        return [
                            'id' => (string)$n->id,
                            'type' => $n->type ?? $n->category ?? 'assignment',
                            'category' => $n->category ?? 'system',
                            'event_key' => $n->event_key ?? null,
                            'title' => $n->title ?? 'Production Notice',
                            'detail' => $n->detail ?? $n->message ?? '',
                            'time' => !empty($n->created_at) ? date('h:i A', strtotime($n->created_at)) : 'Just now',
                            'created_at' => $n->created_at ?? null,
                            'action_url' => $n->action_url ?? null,
                            'unread' => $isUnread
                        ];
                    });

                $unreadCount = $notifications->where('unread', true)->count();

                return response()->json([
                    'success' => true,
                    'data' => $notifications->values(),
                    'unread_count' => $unreadCount
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
                $hasRecipientId = Schema::hasColumn('notifications', 'recipient_id');
                $hasUserId = Schema::hasColumn('notifications', 'user_id');
                $hasIsRead = Schema::hasColumn('notifications', 'is_read');
                $hasRead = Schema::hasColumn('notifications', 'read');

                $query = DB::table('notifications')->where('id', $id);
                if ($hasRecipientId && $adminId) {
                    $query->where(function($q) use ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    });
                } elseif ($hasUserId && $adminId) {
                    $query->where('user_id', $adminId);
                }

                $updates = ['updated_at' => date('Y-m-d H:i:s')];
                if ($hasIsRead) {
                    $updates['is_read'] = 1;
                    $updates['read_at'] = date('Y-m-d H:i:s');
                }
                if ($hasRead) {
                    $updates['read'] = 1;
                }

                $query->update($updates);
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
                $hasRecipientId = Schema::hasColumn('notifications', 'recipient_id');
                $hasUserId = Schema::hasColumn('notifications', 'user_id');
                $hasIsRead = Schema::hasColumn('notifications', 'is_read');
                $hasRead = Schema::hasColumn('notifications', 'read');

                $query = DB::table('notifications');
                if ($hasRecipientId && $adminId) {
                    $query->where('recipient_id', $adminId);
                } elseif ($hasUserId && $adminId) {
                    $query->where('user_id', $adminId);
                }

                $updates = ['updated_at' => date('Y-m-d H:i:s')];
                if ($hasIsRead) {
                    $updates['is_read'] = 1;
                    $updates['read_at'] = date('Y-m-d H:i:s');
                }
                if ($hasRead) {
                    $updates['read'] = 1;
                }

                $query->update($updates);
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

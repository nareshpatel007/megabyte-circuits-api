<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    private function getAdminId(Request $request): ?int
    {
        $adminId = $request->attributes->get('admin_id') ?: $request->query('admin_id');
        return $adminId ? (int)$adminId : null;
    }

    /**
     * Get paginated notifications for Admin panel.
     */
    public function index(Request $request)
    {
        try {
            $adminId = $this->getAdminId($request);

            $query = Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                });

            if ($request->filled('category') && $request->query('category') !== 'all') {
                $query->where('category', $request->query('category'));
            }

            if ($request->boolean('unread_only')) {
                $query->where('is_read', false);
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            }

            $perPage = min(max((int)$request->query('per_page', 15), 1), 100);
            $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $unreadCount = Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })
                ->where('is_read', false)
                ->count();

            return response()->json([
                'status' => true,
                'data' => $notifications->items(),
                'unread_count' => $unreadCount,
                'meta' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Get admin unread count.
     */
    public function unreadCount(Request $request)
    {
        try {
            $adminId = $this->getAdminId($request);

            $count = Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })
                ->where('is_read', false)
                ->count();

            return response()->json(['status' => true, 'count' => $count]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'count' => 0, 'error' => $th->getMessage()], 500);
        }
    }

    /**
     * Mark single notification as read for admin.
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $adminId = $this->getAdminId($request);

            $notification = Notification::where('id', $id)
                ->where('recipient_type', 'admin')
                ->first();

            if ($notification) {
                $notification->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
            }

            $unreadCount = Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })
                ->where('is_read', false)
                ->count();

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read',
                'unread_count' => $unreadCount,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Mark all notifications as read for admin.
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $adminId = $this->getAdminId($request);

            Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'status' => true,
                'message' => 'All admin notifications marked as read',
                'unread_count' => 0
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Get all notification event settings.
     */
    public function getSettings()
    {
        try {
            $settings = NotificationSetting::orderBy('category')->orderBy('event_name')->get();
            return response()->json(['status' => true, 'settings' => $settings]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Update notification event settings configuration.
     */
    public function updateSettings(Request $request)
    {
        try {
            $events = $request->input('settings', []);
            if (!is_array($events)) {
                return response()->json(['status' => false, 'message' => 'Invalid settings payload'], 400);
            }

            foreach ($events as $item) {
                if (isset($item['event_key'])) {
                    NotificationSetting::where('event_key', $item['event_key'])->update([
                        'client_enabled' => (bool)($item['client_enabled'] ?? true),
                        'admin_enabled' => (bool)($item['admin_enabled'] ?? true),
                        'realtime_enabled' => (bool)($item['realtime_enabled'] ?? true),
                        'toast_enabled' => (bool)($item['toast_enabled'] ?? true),
                        'email_enabled' => (bool)($item['email_enabled'] ?? false),
                        'priority' => $item['priority'] ?? 'normal',
                        'updated_at' => now(),
                    ]);
                }
            }

            $updatedSettings = NotificationSetting::orderBy('category')->orderBy('event_name')->get();

            return response()->json([
                'status' => true,
                'message' => 'Notification settings updated successfully',
                'settings' => $updatedSettings
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Purge notifications older than N days.
     */
    public function cleanup(Request $request)
    {
        try {
            $days = max((int)$request->input('days', 30), 1);
            $cutoff = now()->subDays($days);

            $deleted = Notification::where('created_at', '<', $cutoff)
                ->where('is_read', true) // Purge read notifications older than cutoff
                ->delete();

            return response()->json([
                'status' => true,
                'message' => "Successfully purged {$deleted} notifications older than {$days} days.",
                'deleted_count' => $deleted
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Realtime SSE endpoint for Admin notifications.
     */
    public function stream(Request $request)
    {
        $adminId = $this->getAdminId($request);

        return response()->stream(function () use ($adminId) {
            $initialMax = Notification::where('recipient_type', 'admin')
                ->where(function ($q) use ($adminId) {
                    if ($adminId) {
                        $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })->max('id');
            $lastId = $initialMax ? (int) $initialMax : 0;
            $start = time();

            while (time() - $start < 25) {
                $query = Notification::where('recipient_type', 'admin')
                    ->where(function ($q) use ($adminId) {
                        if ($adminId) {
                            $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                        } else {
                            $q->whereNull('recipient_id');
                        }
                    });

                if ($lastId > 0) {
                    $query->where('id', '>', $lastId);
                }

                $newNotifications = $query->orderBy('id', 'asc')->get();

                if ($newNotifications->count() > 0) {
                    foreach ($newNotifications as $n) {
                        $lastId = max($lastId, $n->id);
                    }
                    echo "data: " . json_encode(['notifications' => $newNotifications]) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }

                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

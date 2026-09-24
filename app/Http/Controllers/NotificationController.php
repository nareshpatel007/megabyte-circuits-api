<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function getUserId(Request $request): ?int
    {
        $userId = $request->query('user_id') ?: $request->input('user_id');
        if (!$userId && $request->attributes->get('user_id')) {
            $userId = $request->attributes->get('user_id');
        }
        return $userId ? (int)$userId : null;
    }

    /**
     * Get paginated notifications for current client user.
     */
    public function index(Request $request)
    {
        try {
            $userId = $this->getUserId($request);
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $query = Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
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

            $unreadCount = Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
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
     * Get unread notifications count for client user.
     */
    public function unreadCount(Request $request)
    {
        try {
            $userId = $this->getUserId($request);
            if (!$userId) {
                return response()->json(['status' => false, 'count' => 0]);
            }

            $count = Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
                })
                ->where('is_read', false)
                ->count();

            return response()->json(['status' => true, 'count' => $count]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'count' => 0, 'error' => $th->getMessage()], 500);
        }
    }

    /**
     * Mark single notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $userId = $this->getUserId($request);

            $notification = Notification::where('id', $id)
                ->where('recipient_type', 'user')
                ->first();

            if ($notification) {
                $notification->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
            }

            $unreadCount = $userId ? Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
                })
                ->where('is_read', false)
                ->count() : 0;

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
     * Mark all notifications as read for client user.
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $userId = $this->getUserId($request);
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
                })
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'status' => true,
                'message' => 'All notifications marked as read',
                'unread_count' => 0
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Realtime Server-Sent Events (SSE) endpoint for instant browser notifications.
     */
    public function stream(Request $request)
    {
        $userId = $this->getUserId($request);

        return response()->stream(function () use ($userId) {
            $initialMax = Notification::where('recipient_type', 'user')
                ->where(function ($q) use ($userId) {
                    if ($userId) {
                        $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
                    } else {
                        $q->whereNull('recipient_id');
                    }
                })->max('id');
            $lastId = $initialMax ? (int) $initialMax : 0;
            $start = time();

            // Loop for 25 seconds max (standard SSE polling connection duration)
            while (time() - $start < 25) {
                $query = Notification::where('recipient_type', 'user')
                    ->where(function ($q) use ($userId) {
                        if ($userId) {
                            $q->where('recipient_id', $userId)->orWhereNull('recipient_id');
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

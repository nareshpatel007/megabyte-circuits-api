<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Dispatch a notification for a user or admin.
     *
     * @param string $eventKey E.g. 'order.created'
     * @param array $params Contains: title, message, entity_type, entity_id, action_url, metadata, icon, theme
     * @param int|null $recipientId Specific user/admin ID or null for all
     * @param string $recipientType 'user' or 'admin'
     * @return Notification|null
     */
    public static function dispatch(string $eventKey, array $params, ?int $recipientId = null, string $recipientType = 'user'): ?Notification
    {
        try {
            // 1. Fetch event settings configuration
            $setting = NotificationSetting::where('event_key', $eventKey)->first();

            if ($setting) {
                if ($recipientType === 'user' && !$setting->client_enabled) {
                    return null;
                }
                if ($recipientType === 'admin' && !$setting->admin_enabled) {
                    return null;
                }
            }

            // 2. Duplicate Check / Idempotency (prevent duplicate notification in last 10 seconds for same entity & recipient)
            $entityType = $params['entity_type'] ?? null;
            $entityId = $params['entity_id'] ?? null;

            if ($entityType && $entityId) {
                $recentCount = Notification::where('event_key', $eventKey)
                    ->where('recipient_type', $recipientType)
                    ->where('recipient_id', $recipientId)
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('created_at', '>=', now()->subSeconds(10))
                    ->count();

                if ($recentCount > 0) {
                    return null;
                }
            }

            // 3. Determine theme & category
            $category = $params['category'] ?? ($setting ? $setting->category : 'system');
            $theme = $params['theme'] ?? ($params['type'] ?? 'info');
            $icon = $params['icon'] ?? self::getDefaultIcon($category, $theme);

            // 4. Create database record
            $notification = Notification::create([
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'event_key' => $eventKey,
                'category' => $category,
                'title' => $params['title'] ?? 'System Notice',
                'message' => $params['message'] ?? '',
                'icon' => $icon,
                'theme' => $theme,
                'action_url' => $params['action_url'] ?? null,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => $params['metadata'] ?? null,
                'is_read' => false,
            ]);

            return $notification;
        } catch (\Throwable $th) {
            Log::error("NotificationService dispatch error: {$th->getMessage()}", [
                'event_key' => $eventKey,
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
            ]);
            return null;
        }
    }

    /**
     * Dispatch notification to a specific client user.
     */
    public static function notifyUser(int $userId, string $eventKey, array $params): ?Notification
    {
        return self::dispatch($eventKey, $params, $userId, 'user');
    }

    /**
     * Dispatch notification to admins (optionally filtered by permission).
     */
    public static function notifyAdmins(string $eventKey, array $params): ?Notification
    {
        return self::dispatch($eventKey, $params, null, 'admin');
    }

    /**
     * Helper to get sensible default icon name for categories.
     */
    private static function getDefaultIcon(string $category, string $theme): string
    {
        switch ($category) {
            case 'order':
                return 'Package';
            case 'payment':
                return 'CreditCard';
            case 'gerber':
                return 'Cpu';
            case 'inventory':
                return 'PackageCheck';
            case 'support':
                return 'Ticket';
            default:
                return $theme === 'error' ? 'AlertCircle' : 'Bell';
        }
    }
}

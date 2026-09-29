<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Notification;
use App\Models\NotificationSetting;

// Make sure order.created is enabled for admin in notification_settings
NotificationSetting::updateOrCreate(
    ['event_key' => 'order.created'],
    [
        'event_name' => 'New Order Created',
        'category' => 'order',
        'admin_enabled' => true,
        'client_enabled' => true,
        'priority' => 'high',
        'recipient_permission' => 'orders.view',
    ]
);

// Create an unread test notification
$notif = Notification::create([
    'recipient_type' => 'admin',
    'recipient_id'   => null, // Broadcast to all admins/staff
    'event_key'      => 'order.created',
    'category'       => 'order',
    'title'          => 'PCB Order #MB-' . rand(3000, 9999) . ' Received',
    'message'        => 'New 4-Layer FR4 prototype order placed by Client. Ready for Traveler review.',
    'icon'           => 'package',
    'theme'          => 'info',
    'action_url'     => '/(tabs)/orders',
    'is_read'        => false,
]);

echo "Created test unread notification with ID: " . $notif->id . "\n";
echo "Title: " . $notif->title . "\n";
echo "Message: " . $notif->message . "\n";

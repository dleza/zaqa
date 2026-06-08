<?php

namespace App\Support\Notifications;

final class NotificationQueue
{
    public static function mail(): string
    {
        return (string) config('notifications.queues.mail', 'notifications');
    }

    public static function sms(): string
    {
        return (string) config('notifications.queues.sms', 'notifications');
    }

    public static function listeners(): string
    {
        return (string) config('notifications.queues.listeners', 'default');
    }
}

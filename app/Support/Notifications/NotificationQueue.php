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
        $configured = config('notifications.queues.sms');

        if ($configured !== null && $configured !== '') {
            return (string) $configured;
        }

        $connection = (string) config('queue.default', 'database');
        $queue = config("queue.connections.{$connection}.queue");

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    public static function listeners(): string
    {
        return (string) config('notifications.queues.listeners', 'default');
    }
}

<?php

namespace Tests\Unit;

use App\Support\Notifications\NotificationQueue;
use Tests\TestCase;

class NotificationQueueSmsTest extends TestCase
{
    public function test_uses_configured_sms_queue_when_set(): void
    {
        config(['notifications.queues.sms' => 'sms']);

        $this->assertSame('sms', NotificationQueue::sms());
    }

    public function test_falls_back_to_default_queue_when_not_configured(): void
    {
        config([
            'notifications.queues.sms' => null,
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);

        $this->assertSame('default', NotificationQueue::sms());
    }
}

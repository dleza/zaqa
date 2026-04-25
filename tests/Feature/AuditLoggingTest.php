<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creation_is_audited(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'identity.user_created',
            'module' => 'Identity',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'action_name' => 'user_created',
        ]);
    }

    public function test_login_event_is_audited(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'identity.login',
            'module' => 'Identity',
            'actor_user_id' => $user->id,
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'action_name' => 'login',
        ]);
    }

    public function test_failed_login_audit_does_not_store_password(): void
    {
        $user = User::factory()->create();

        event(new Failed('web', $user, [
            'email' => $user->email,
            'password' => 'secret',
        ]));

        $log = AuditLog::query()
            ->where('event_type', 'identity.login_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(User::class, $log->entity_type);
        $this->assertSame($user->id, $log->entity_id);
        $this->assertIsArray((array) $log->metadata);
        $this->assertArrayNotHasKey('password', (array) ($log->metadata['credentials'] ?? []));
    }

    public function test_request_id_header_is_added(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Request-Id');
    }
}


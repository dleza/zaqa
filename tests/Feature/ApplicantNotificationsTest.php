<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_view_notifications_page(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($user)
            ->get('/applicant/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Applicant/Notifications/Index', false));
    }

    public function test_applicant_notification_mark_read_is_scoped_to_user(): void
    {
        $userA = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $userB = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $userB->notify(new ApplicantTestPortalNotification(
            title: 'Test notification',
            message: 'Hello from the portal.',
            linkUrl: '/applicant/dashboard',
        ));

        $note = $userB->fresh()->unreadNotifications()->firstOrFail();

        $this->actingAs($userA)
            ->post("/applicant/notifications/{$note->id}/read")
            ->assertNotFound();

        $this->actingAs($userB)
            ->post("/applicant/notifications/{$note->id}/read")
            ->assertRedirect();

        $this->assertSame(0, $userB->fresh()->unreadNotifications()->count());
    }

    public function test_applicant_notification_open_marks_read_and_redirects_to_link(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $user->notify(new ApplicantTestPortalNotification(
            title: 'Test notification',
            message: 'Open me.',
            linkUrl: '/applicant/dashboard',
        ));

        $note = $user->fresh()->unreadNotifications()->firstOrFail();

        $this->actingAs($user)
            ->post("/applicant/notifications/{$note->id}/open")
            ->assertRedirect('/applicant/dashboard');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }
}

class ApplicantTestPortalNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $linkUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'test.applicant_portal';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'link_url' => $this->linkUrl,
        ];
    }
}


<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Avoid stale permission caches across permission/role changes.
        if ($request->user()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'phone_primary' => $request->user()->phone_primary,
                        'profile_photo_url' => $request->user()->profile_photo_url,
                        'applicant_type' => $request->user()->applicant_type?->value,
                        'is_active' => (bool) $request->user()->is_active,
                        'email_verified_at' => $request->user()->email_verified_at,
                        'phone_verified_at' => $request->user()->phone_verified_at,
                    ]
                    : null,
                'permissions' => $request->user()
                    ? $request->user()->getAllPermissions()->pluck('name')->values()->all()
                    : [],
                'notifications_unread_count' => fn () => $request->user()
                    ? $request->user()->unreadNotifications()->count()
                    : 0,
                'notifications' => fn () => $request->user()
                    ? $request->user()
                        ->notifications()
                        ->orderByDesc('created_at')
                        ->limit(8)
                        ->get()
                        ->map(fn (DatabaseNotification $n) => [
                            'id' => (string) $n->id,
                            'type' => (string) $n->type,
                            'title' => (string) ($n->data['title'] ?? ''),
                            'message' => (string) ($n->data['message'] ?? ''),
                            'link_url' => (string) ($n->data['link_url'] ?? ''),
                            'data' => $n->data,
                            'read_at' => optional($n->read_at)?->toIso8601String(),
                            'created_at' => optional($n->created_at)?->toIso8601String(),
                        ])
                        ->values()
                        ->all()
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'import_report' => fn () => $request->session()->get('import_report'),
                'created_qualification_id' => fn () => $request->session()->get('created_qualification_id'),
                'payment_completed' => fn () => $request->session()->get('payment_completed'),
            ],
        ]);
    }
}

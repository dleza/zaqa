<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
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
                        'applicant_type' => $request->user()->applicant_type?->value,
                        'is_active' => (bool) $request->user()->is_active,
                        'email_verified_at' => $request->user()->email_verified_at,
                        'phone_verified_at' => $request->user()->phone_verified_at,
                    ]
                    : null,
                'permissions' => $request->user()
                    ? $request->user()->getAllPermissions()->pluck('name')->values()->all()
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}

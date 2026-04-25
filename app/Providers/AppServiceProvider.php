<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);

        RedirectIfAuthenticated::redirectUsing(function (Request $request) {
            $user = $request->user();
            if (! $user) {
                return route('login');
            }
            if (! $user->is_active) {
                return route('activation.show');
            }
            if ($user->can('dashboard.view')) {
                return route('admin.dashboard');
            }

            return route('applicant.dashboard');
        });
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('institution-api', function (Request $request) {
            $perMinute = (int) (config('institution_api.rate_limit_per_minute', 60) ?: 60);

            $user = $request->user();
            $clientId = is_object($user) && isset($user->id) ? (int) $user->id : 0;
            $tokenId = method_exists($user, 'currentAccessToken') && $user->currentAccessToken()
                ? (int) $user->currentAccessToken()->getKey()
                : 0;

            return Limit::perMinute($perMinute)->by('institution-api:'.$clientId.':'.$tokenId);
        });

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

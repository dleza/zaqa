<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Domain\Payments\CGratePollingService;
use App\Support\Uploads\UserUploadLimit;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(function () {
            if (! (bool) config('cgrate.enabled')) {
                return;
            }

            app(CGratePollingService::class)->dispatchDueAttempts();
        })
            ->everyMinute()
            ->name('cgrate.poll_due_attempts')
            ->withoutOverlapping();

        $schedule->command('quotations:expire')
            ->daily()
            ->name('quotations.expire_due')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Add Sanctum ability middleware aliases (API tokens).
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'institution.applicant' => \App\Http\Middleware\EnsureInstitutionApplicant::class,
        ]);

        $middleware->append(\App\Http\Middleware\EnsureCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $sessionExpiredMessage = 'Your session expired due to inactivity. Please log in again.';

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($sessionExpiredMessage) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $sessionExpiredMessage], 419);
            }

            return redirect()
                ->route('login')
                ->with('error', $sessionExpiredMessage);
        });

        $exceptions->render(function (HttpException $e, Request $request) use ($sessionExpiredMessage) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $sessionExpiredMessage], 419);
            }

            return redirect()
                ->route('login')
                ->with('error', $sessionExpiredMessage);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($sessionExpiredMessage) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $sessionExpiredMessage], 401);
            }

            $redirect = redirect()->guest(route('login'));
            $sessionCookie = (string) config('session.cookie');

            if ($sessionCookie !== '' && $request->cookies->has($sessionCookie)) {
                $redirect->with('error', $sessionExpiredMessage);
            }

            return $redirect;
        });

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = UserUploadLimit::fileTooLargeMessage();

            if ($request->header('X-Inertia') || $request->expectsJson()) {
                return back()->withErrors(['file' => $message])->withInput();
            }

            return back()->withErrors(['file' => $message])->withInput();
        });
    })->create();

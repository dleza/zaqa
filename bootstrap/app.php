<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Domain\Payments\CGratePollingService;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->append(\App\Http\Middleware\EnsureCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

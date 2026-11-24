<?php

use App\Http\Middleware\EnsureActivePlatformAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['account.active' => EnsureActivePlatformAccount::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('ai:purge-usage')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('meetings:send-reminders')->everyMinute()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

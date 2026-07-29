<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->alias([
            'active.user' => \App\Http\Middleware\EnsureUserIsActive::class,
            'admin.staff' => \App\Http\Middleware\EnsureUserIsAdminOrStaff::class,
            'agent.user' => \App\Http\Middleware\EnsureUserIsAgent::class,
            'jwt.auth' => \App\Http\Middleware\JWTMiddleware::class,
            'member.user' => \App\Http\Middleware\EnsureUserIsMember::class,
        ]);
    })
    // Scheduling lives in routes/console.php. Do not add a withSchedule() block
    // here as well: Laravel runs both, which previously scheduled
    // equb:process-automatic-draws hourly *and* at 09:00, with no overlap guard.
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

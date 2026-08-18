<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Render API failures as JSON.
        //
        // Without this, a mistyped path, an expired token or an unhandled
        // error returns Laravel's HTML error page. An integrating system
        // cannot parse that, and a partner evaluating the service reads it
        // as an outage rather than as a bad request.
        //
        // Matching on 'api' as well as 'api/*' is deliberate: Laravel's
        // decoded path for a request to /api/ is 'api', which 'api/*' alone
        // does not match.
        $isApi = static fn (Request $request): bool => $request->is('api')
            || $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $e): bool => $isApi($request)
                || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Validation failures keep Laravel's default shape. Shipped
            // mobile clients already parse it, and reshaping it here would
            // break them for no gain.
            if ($e instanceof ValidationException) {
                return null;
            }

            [$status, $message] = match (true) {
                $e instanceof AuthenticationException => [
                    401, 'Unauthenticated. Supply a valid bearer token.',
                ],
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => [
                    403, 'This account is not permitted to perform that action.',
                ],
                $e instanceof NotFoundHttpException => [
                    404, 'No resource exists at that path.',
                ],
                $e instanceof MethodNotAllowedHttpException => [
                    405, 'That HTTP method is not supported on this path.',
                ],
                $e instanceof ThrottleRequestsException => [
                    429, 'Too many requests. Please retry shortly.',
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(), $e->getMessage() ?: 'The request could not be completed.',
                ],
                default => [
                    500, 'An unexpected error occurred. The incident has been logged.',
                ],
            };

            $payload = [
                'status' => 'error',
                'message' => $message,
            ];

            // A wrong path should be self-correcting rather than a dead end.
            if ($status === 404) {
                $payload['documentation'] = config('api.documentation_url');
            }

            // Internals are surfaced while debugging and never in production,
            // where a stack trace or a query string is a disclosure.
            if ($status >= 500 && config('app.debug')) {
                $payload['exception'] = $e::class;
                $payload['detail'] = $e->getMessage();
            }

            return response()->json($payload, $status);
        });
    })->create();

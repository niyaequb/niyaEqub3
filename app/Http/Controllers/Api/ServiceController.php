<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service-level endpoints: the API index and the health check.
 *
 * Neither endpoint is authenticated and neither touches member data. The
 * index exists because the base URL is the first thing an integrator opens,
 * and an unadorned 404 there reads as a broken service rather than as a
 * path with no resource on it.
 *
 * @group Service
 */
class ServiceController extends Controller
{
    /**
     * Service index
     *
     * Confirms the caller has reached the correct deployment and points them at the
     * specification. Use this as a connectivity smoke test during integration setup:
     * it touches no dependencies, so a 200 here means only that the application is
     * serving. Use the health check to confirm the dependencies behind it.
     *
     * @unauthenticated
     *
     * @response 200 {
     *   "status": "success",
     *   "service": "Niya Umrah Equb API",
     *   "version": "2.1",
     *   "documentation": "https://niya-et.com/developers",
     *   "support": "support@niya-et.com",
     *   "server_time": "2026-08-18T09:12:44+00:00",
     *   "resources": {
     *     "health": "https://cms.niya-et.com/api/health",
     *     "settings": "https://cms.niya-et.com/api/settings",
     *     "login": "https://cms.niya-et.com/api/auth/login"
     *   }
     * }
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'service' => config('api.name'),
            'version' => config('api.version'),
            'documentation' => config('api.documentation_url'),
            'support' => config('api.support_email'),
            'server_time' => now()->toIso8601String(),
            'resources' => [
                'health' => url('/api/health'),
                'settings' => url('/api/settings'),
                'login' => url('/api/auth/login'),
            ],
        ]);
    }

    /**
     * Health check
     *
     * Dependency probe for uptime monitoring and partner smoke tests. Returns 200 while
     * every dependency responds and 503 as soon as one does not, so a monitor can alert
     * on the status code alone.
     *
     * Failure detail is logged server-side and never returned: this endpoint is public,
     * and a connection string in an error message is a disclosure.
     *
     * @unauthenticated
     *
     * @response 200 {
     *   "status": "success",
     *   "health": "ok",
     *   "checks": { "database": "ok", "cache": "ok" },
     *   "version": "2.1",
     *   "server_time": "2026-08-18T09:12:44+00:00"
     * }
     * @response 503 {
     *   "status": "error",
     *   "health": "degraded",
     *   "checks": { "database": "unavailable", "cache": "ok" },
     *   "version": "2.1",
     *   "server_time": "2026-08-18T09:12:44+00:00"
     * }
     */
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo(), 'database'),
            'cache' => $this->check(function () {
                Cache::put('health:ping', true, 10);

                return Cache::get('health:ping') === true;
            }, 'cache'),
        ];

        $healthy = ! in_array('unavailable', $checks, true);

        return response()->json([
            'status' => $healthy ? 'success' : 'error',
            'health' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'version' => config('api.version'),
            'server_time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Run one dependency probe, converting any failure into a flat status.
     */
    private function check(callable $probe, string $name): string
    {
        try {
            return $probe() === false ? 'unavailable' : 'ok';
        } catch (Throwable $e) {
            Log::error("Health check failed: {$name}", ['exception' => $e]);

            return 'unavailable';
        }
    }
}

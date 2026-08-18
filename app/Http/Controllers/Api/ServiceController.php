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
 */
class ServiceController extends Controller
{
    /**
     * GET /api
     *
     * Service index. Confirms the caller has reached the right deployment
     * and points them at the specification.
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
     * GET /api/health
     *
     * Dependency check for uptime monitoring and partner smoke tests.
     * Returns 200 while every dependency responds and 503 as soon as one
     * does not, so a monitor can alert on the status code alone.
     *
     * Failure details are logged, never returned: this endpoint is public,
     * and a connection string in an error message is a disclosure.
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Is there a newer build, and can I keep using this one?"
 *
 * Deliberately public. The app asks on launch, before anyone has signed in,
 * and a version prompt is exactly what a user on a broken build needs to see
 * when login itself is what stopped working.
 *
 * @group App
 */
class AppVersionController extends Controller
{
    /**
     * GET /app-version?platform=android&version=1.0.1+22
     */
    public function check(Request $request, AppVersionService $versions): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:android,ios'],
            'version' => ['nullable', 'string', 'max:32'],
            'build' => ['nullable', 'string', 'max:16'],
        ]);

        $version = $data['version'] ?? null;

        // Some clients send the build separately rather than as a +suffix.
        // Stitching it back on here means the service only has one shape to
        // understand.
        if ($version !== null && ! str_contains($version, '+') && ! empty($data['build'])) {
            $version .= '+'.$data['build'];
        }

        return response()->json([
            'status' => 'success',
            'data' => $versions->statusFor($data['platform'] ?? 'android', $version),
        ]);
    }
}

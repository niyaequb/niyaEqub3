<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JWTMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Check if token exists
            if (! $token = JWTAuth::getToken()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Token not provided.',
                ], 401);
            }

            // Authenticate user
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 401);
            }

            // Check if user is active
            if (! $user->is_active) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account is deactivated.',
                ], 403);
            }

            // Check phone verification for non-staff users
            if (! $user->isStaff() && ! $user->isPhoneVerified()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Phone number not verified.',
                ], 403);
            }

            // Add user to request
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token has expired.',
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token is invalid.',
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token error.',
            ], 401);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CheckUserRequest;
use App\Http\Requests\Auth\DeleteAccountByPhone;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Send OTP for phone verification.
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $result = $this->authService->sendOtp($request->phone);

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    public function checkUser(CheckUserRequest $request): JsonResponse
    {
        $phone = $request->input('phone');
        $result = $this->authService->checkUserExists($phone);

        return response()->json($result, 200);
    }

    /**
     * Verify OTP code.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyOtp($request->phone, $request->verificationId, $request->code);

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json($result, $result['status'] === 'success' ? 201 : 400);
    }

    /**
     * Authenticate user and return JWT token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->only(['phone', 'password']));

        return response()->json($result, $result['status'] === 'success' ? 200 : 401);
    }

    /**
     * Send forgot password OTP.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->forgotPassword($request->phone);

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    /**
     * Reset password using OTP.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->resetPassword(
            $request->phone,
            $request->password
        );

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    /**
     * Get authenticated user information.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->getUser();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'user' => $user->load('member','agentProfile')->makeHidden(['password']),
            'profile_picture_url' => $user->profile_picture_url,
        ]);
    }

    /**
     * Logout user by invalidating token.
     */
    public function logout(): JsonResponse
    {
        $result = $this->authService->logout();

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    // delete account
    public function deleteAccount(): JsonResponse
    {
        $result = $this->authService->deleteAccount();

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    // delete account by phone
    public function deleteAccountByPhone(DeleteAccountByPhone $request): JsonResponse
    {
        $phone = $request->input('phone');
        $result = $this->authService->deleteAccountByPhone($phone);

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }

    /**
     * Refresh JWT token.
     */
    public function refresh(): JsonResponse
    {
        $result = $this->authService->refreshToken();

        return response()->json($result, $result['status'] === 'success' ? 200 : 400);
    }
}

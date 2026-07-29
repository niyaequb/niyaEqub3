<?php

namespace App\Services;

use App\Enums\RegisteredVia;
use App\Models\Agent;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Facades\JWTAuth;

use function Symfony\Component\Clock\now;

class AuthService
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send OTP for phone verification.
     */
    public function sendOtp(string $phone): array
    {
        return $this->smsService->sendOtp($phone, '');
    }

    /**
     * Verify OTP code.
     */
    public function verifyOtp($to, $verificationId, $code): array
    {
        $provider = $this->smsService->getActiveProvider();

        if ($provider === 'AFRO') {
            return $this->smsService->verifyAfroOtp($to, $verificationId, $code);
        } elseif ($provider === 'GEEZ') {
            return $this->smsService->verifyGeezOtp($to, $code);
        }

        return [
            'status' => 'error',
            'message' => 'No active SMS provider configured for verification.',
        ];
    }

    /**
     * Register a new user (member or agent).
     */
    public function register(array $data): array
    {
        try {
            $user = DB::transaction(function () use ($data) {
                $type = $data['type'] ?? 'member';

                if ($type === 'agent') {
                    return $this->registerAgent($data);
                }

                return $this->registerMember($data);
            });

            $token = JWTAuth::fromUser($user);

            return [
                'status' => 'success',
                'message' => 'Registration successful.',
                'user' => $user,
                'token' => null,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Registration failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Register a new member (optionally with referral code).
     *
     * @param  array<string, mixed>  $data
     */
    private function registerMember(array $data): User
    {
        $agent = null;
        $referralCode = $data['referral_code'] ?? null;

        if ($referralCode) {
            $agent = Agent::query()
                ->where('referral_code', $referralCode)
                ->where('is_active', true)
                ->first();
        }

        $user = User::create([
            'name' => $data['full_name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'type' => 'member',
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->member()->create([
            'full_name' => $data['full_name'],
            'agent_id' => $agent?->id,
            'city' => $data['city'] ?? null,
            'registered_via' => $agent ? RegisteredVia::Agent : RegisteredVia::Direct,
            'referral_code_used' => $agent ? $referralCode : null,
            'registered_at' => now(),
        ]);

        return $user->load('member');
    }

    /**
     * Register a new agent (with auto-generated referral code).
     *
     * @param  array<string, mixed>  $data
     */
    private function registerAgent(array $data): User
    {
        $user = User::create([
            'name' => $data['full_name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'type' => 'agent',
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);

        Agent::create([
            'user_id' => $user->id,
            'referral_code' => $this->generateReferralCode(),
            'commission_rule_id' => null,
            'is_active' => false,
            'joined_at' => now(),
        ]);

        return $user->load('agentProfile');
    }

    private function generateReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Agent::query()->where('referral_code', $code)->exists());

        return $code;
    }

    // delete account
    public function deleteAccount(): array
    {
        $user = $this->getUser();

        if (! $user) {
            return [
                'status' => 'error',
                'message' => 'Unauthorized.',
            ];
        }

        // $user->delete();
        // Instead of hard deleting, we can soft delete or deactivate the account
        $user->update(['is_active' => false]);

        return [
            'status' => 'success',
            'message' => 'Account deleted successfully.',
        ];
    }

    // delete account by phone
    public function deleteAccountByPhone(string $phone): array
    {
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return [
                'status' => 'error',
                'message' => 'User not found.',
            ];
        }
        $user->delete();

        return [
            'status' => 'success',
            'message' => 'Account deleted successfully.',
        ];
    }

    /**
     * Authenticate user and generate JWT token.
     */
    public function login(array $credentials): array
    {
        $phone = $credentials['phone'];
        $password = $credentials['password'];

        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return [
                'status' => 'error',
                'message' => 'User not found with the provided phone number.',
            ];
        }

        if (! Hash::check($password, $user->password)) {
            return [
                'status' => 'error',
                'message' => 'Invalid credentials.',
            ];
        }

        if (! $user->is_active) {
            return [
                'status' => 'error',
                'message' => 'Account is deactivated.',
            ];
        }

        if (! $user->isPhoneVerified()) {
            return [
                'status' => 'error',
                'message' => 'Phone number not verified. Please verify your phone first.',
            ];
        }

        // Update last login
        $user->updateLastLogin();

        $token = JWTAuth::fromUser($user);

        return [
            'status' => 'success',
            'message' => 'Login successful.',
            'user' => $user->load('member','agentProfile'),
            'token' => $token,
        ];
    }

    /**
     * Send forgot password OTP.
     */
    public function forgotPassword(string $phone): array
    {
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return [
                'status' => 'error',
                'message' => 'Phone number not found.',
            ];
        }

        if (! $user->is_active) {
            return [
                'status' => 'error',
                'message' => 'Account is deactivated.',
            ];
        }

        return $this->sendOtp($phone);
    }

    /**
     * Reset password using OTP.
     */
    public function resetPassword(string $phone, string $newPassword): array
    {
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return [
                'status' => 'error',
                'message' => 'User not found.',
            ];
        }

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        // Update last login
        $user->updateLastLogin();
        $token = JWTAuth::fromUser($user);

        return [
            'status' => 'success',
            'message' => 'Password reset successfully.',
            'token' => $token,
            'user' => $user->load('member'),
        ];
    }

    public function checkUserExists(string $phone): array
    {
        $user = User::where('phone', $phone)->first();

        if ($user) {
            return [
                'status' => 'exists',
                'message' => 'User exists with this phone number.',
                'user' => $user,
            ];
        } else {
            return [
                'status' => 'not_found',
                'message' => 'No user found with this phone number.',
            ];
        }
    }

    /**
     * Get authenticated user info.
     */
    public function getUser(): ?User
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Logout user by invalidating token.
     */
    public function logout(): array
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return [
                'status' => 'success',
                'message' => 'Logged out successfully.',
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Logout failed.',
            ];
        }
    }

    /**
     * Refresh JWT token.
     */
    public function refreshToken(): array
    {
        try {
            $newToken = JWTAuth::parseToken()->refresh();

            return [
                'status' => 'success',
                'message' => 'Token refreshed successfully.',
                'token' => $newToken,
            ];
        } catch (TokenExpiredException $e) {
            return [
                'status' => 'error',
                'message' => 'Token is fully expired. Please login again.',
            ];
        } catch (JWTException $e) {
            return [
                'status' => 'error',
                'message' => 'Token is invalid or missing.',
            ];
        }
    }
}

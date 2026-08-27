<?php

namespace App\Services;

use App\Enums\RegisteredVia;
use App\Models\Agent;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;
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

            // Issued and RETURNED. It used to be issued and thrown away, with
            // `'token' => null` hard-coded below it, which left the apps to
            // make a second round trip to /auth/login immediately after every
            // signup just to get the token that had already been minted here.
            // That also made registration silently depend on login working:
            // when login broke, signup appeared to break too, in a way that
            // pointed at the wrong endpoint.
            //
            // A signing failure is caught rather than raised, and this is the
            // one place that is right. The transaction above has ALREADY
            // committed, so the account exists. Reporting "registration
            // failed" would be a lie that sends the member back to sign up
            // again, where they would be told their phone is already taken and
            // conclude the app is broken. Both apps already treat a null token
            // as "go to the login screen", which is exactly where a member
            // should be told that signing in is temporarily unavailable.
            try {
                $token = $this->issueToken($user);
            } catch (ServiceUnavailableHttpException) {
                $token = null;
            }

            return [
                'status' => 'success',
                'message' => 'Registration successful.',
                'user' => $user,
                'token' => $token,
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

        $token = $this->issueToken($user);

        return [
            'status' => 'success',
            'message' => 'Login successful.',
            'user' => $user->load('member', 'agentProfile'),
            'token' => $token,
        ];
    }

    /**
     * Mint a JWT, and fail in a way somebody can act on.
     *
     * WHY THIS IS NOT JUST JWTAuth::fromUser()
     *
     * Every other statement on the login path returns a tidy
     * `['status' => 'error']` array. This one call did not: it was bare, and
     * it is the only thing in the whole public login flow that can throw. So a
     * configuration problem here escaped as an uncaught exception, hit the
     * `default` arm in bootstrap/app.php, and reached members as a flat
     * HTTP 500 reading "An unexpected error occurred. The incident has been
     * logged." — indistinguishable from a database outage, and impossible to
     * diagnose from the phone.
     *
     * The overwhelmingly common cause is an empty JWT_SECRET. config/jwt.php
     * is `env('JWT_SECRET')` with no default, and the Lcobucci provider throws
     * while CONSTRUCTING its signer on an empty key — before any of the
     * package's own error handling gets a chance to run. A deployment that
     * never ran `php artisan jwt:secret`, or one whose config cache was built
     * before the platform injected its environment variables, lands here.
     *
     * The tell is that registration kept working while login did not: register
     * wrapped the same call in a try/catch and login did not.
     *
     * Callers that must not fail on a signing problem — register() and
     * resetPassword(), which have both already committed a write by the time
     * they get here — catch the exception and carry on with a null token.
     * Nobody else should: a sign-in that cannot issue a token has not signed
     * anybody in.
     *
     * Public because it is not only this class that mints sessions.
     * MiniAppController does too, for sign-in through a bank's host app, and
     * it had the same bare call. Every JWT this application issues to a member
     * now comes through here, which is the only way the guarantee above holds.
     *
     * @throws ServiceUnavailableHttpException when a token cannot be signed
     */
    public function issueToken(User $user): string
    {
        try {
            return JWTAuth::fromUser($user);
        } catch (Throwable $e) {
            $missingSecret = blank(config('jwt.secret'));

            Log::error('Could not issue a JWT.', [
                'user_id' => $user->id,
                'jwt_secret_configured' => ! $missingSecret,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'hint' => $missingSecret
                    ? 'JWT_SECRET is empty. Run `php artisan jwt:secret`, then `php artisan optimize:clear`.'
                    : 'JWT_SECRET is set, so this is not the usual cause. Read the exception above.',
            ]);

            // 503, not 500. This is a server that is configured wrong rather
            // than a request that went wrong, and the distinction is the whole
            // difference between "retry later" and "your password is wrong"
            // for the person holding the phone.
            //
            // The specific cause goes to the log and, while debugging, to the
            // response. It does NOT go to the response in production: on a
            // public unauthenticated endpoint, "this server has no signing
            // key" is free reconnaissance for anyone who asks politely.
            throw new ServiceUnavailableHttpException(null, config('app.debug') && $missingSecret
                ? 'Sign-in is unavailable: the server has no JWT_SECRET, so it cannot sign a login token. Run `php artisan jwt:secret` and then `php artisan optimize:clear`.'
                : 'Sign-in is temporarily unavailable. Please try again shortly.', $e);
        }
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

        // The third public token-minting path, and it had the same bare call
        // that broke login. Hardening two of the three would have left the
        // identical HTTP 500 waiting on the one route a locked-out member is
        // most likely to reach for.
        //
        // Degraded rather than raised, for the same reason as register(): the
        // password has ALREADY been changed by the update above. Reporting a
        // failure here would send the member back to try again with their old
        // password — which no longer works — and they would lock themselves
        // out by drawing an entirely reasonable conclusion.
        try {
            $token = $this->issueToken($user);
        } catch (ServiceUnavailableHttpException) {
            $token = null;
        }

        return [
            'status' => 'success',
            'message' => $token === null
                ? 'Password reset successfully. Please sign in with your new password.'
                : 'Password reset successfully.',
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

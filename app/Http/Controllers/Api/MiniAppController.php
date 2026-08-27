<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Signing in through a bank's host app.
 *
 * Every bank super-app works the same way here: the mini app asks the host for
 * an opaque customer identifier over a JS bridge and POSTs it to us; we exchange
 * it with that bank for the customer's identity and, when it maps onto an
 * existing Niya member, issue the same JWT an ordinary phone-and-password
 * sign-in would. Dashen calls it "Login with DBSA"; the others will call it
 * something else and behave identically.
 *
 * The bank is a route parameter, so this controller names none of them.
 *
 * WHY THE EXCHANGE HAPPENS HERE AND NOT IN THE MINI APP
 *
 * The identifier is a bearer of identity. If the mini app exchanged it itself
 * it would need the app secret in browser JavaScript, where anyone can read it
 * and mint their own orders. Dashen's own sample makes the same call — its
 * callback handler posts to `https://merchant.domain/miniapp/get-identifier` —
 * for exactly this reason.
 *
 * @group Payments
 */
class MiniAppController extends Controller
{
    /**
     * Resolve a host-app customer identifier into a Niya session.
     */
    public function identify(
        Request $request,
        string $provider,
        PaymentGatewayManager $gateways,
        AuthService $auth,
    ): JsonResponse {
        $gateway = $gateways->tryGet($provider);

        if (! $gateway || ! $gateway->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unknown payment provider.',
            ], 404);
        }

        $data = $request->validate([
            'customeridentifier' => ['required', 'string', 'max:2048'],
            'appcode' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:32'],
        ]);

        // The appcode is the mini app's own identity, not the customer's, so a
        // mismatch means the request did not come from our mini app. Checked
        // only when sent, because a bank's callback handler is free to omit it
        // and refusing a well-formed login over a missing optional field would
        // lock members out for no security gain.
        $expectedAppCode = $gateway->clientConfig()['app_code'] ?? null;

        if (! empty($data['appcode']) && $expectedAppCode && $data['appcode'] !== $expectedAppCode) {
            Log::warning('Mini-app identifier rejected: appcode mismatch', [
                'provider' => $provider,
                'received' => $data['appcode'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unknown mini app.',
            ], 403);
        }

        $result = $gateway->exchangeCustomerIdentifier($data['customeridentifier']);

        if (! ($result['success'] ?? false)) {
            // An unconfigured or unsupported exchange is an integration gap,
            // not a rejected member. 503 says "come back later"; the app falls
            // through to ordinary phone-and-OTP sign-in either way, so nobody
            // is stranded.
            $gap = ($result['unconfigured'] ?? false) || ($result['unsupported'] ?? false);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Could not verify the session.',
            ], $gap ? 503 : 401);
        }

        $user = $this->findUserByPhone($result['phone'] ?? null);

        if (! $user) {
            // Recognised by the bank, unknown to us. Not an error: this is a
            // first-time member, and the app should send them through
            // registration with the phone pre-filled rather than showing a
            // sign-in failure for an account that was never going to exist.
            return response()->json([
                'status' => 'success',
                'registered' => false,
                'provider' => $gateway->slug(),
                'phone' => $result['phone'] ?? null,
                'message' => 'No Niya account yet for this customer.',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'registered' => true,
            'provider' => $gateway->slug(),
            // Minted through AuthService rather than JWTAuth directly. The
            // bare facade call that used to be here shared the fault that
            // broke ordinary login: a signing failure — an empty JWT_SECRET,
            // most often — threw uncaught and reached the member as a flat
            // HTTP 500 saying nothing. issueToken() turns that into a 503 with
            // a message, which is what the mini app needs to fall back to
            // phone-and-OTP instead of appearing broken.
            'token' => $auth->issueToken($user),
            // The bank's own session token, where it issued one. The app keeps
            // it and presents it back as `xAccessToken` when authorising an
            // order; the server does not store it.
            'session_token' => $result['token'] ?? null,
            'user' => $user,
        ]);
    }

    /**
     * Match a phone number however the bank chose to format it.
     *
     * Ethiopian numbers arrive as +251912222222, 251912222222 or 0912222222
     * depending on which system last touched them, and the users table holds
     * whichever form was typed at registration. Matching on the national
     * significant number — the nine digits after the country code or the
     * leading zero — is the one comparison stable across all three.
     */
    protected function findUserByPhone(?string $phone): ?User
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        $national = $digits;
        if (str_starts_with($national, '251')) {
            $national = substr($national, 3);
        }
        $national = ltrim($national, '0');

        if (strlen($national) < 9) {
            return null;
        }

        $national = substr($national, -9);

        return User::query()
            ->where(fn ($q) => $q
                ->where('phone', '+251'.$national)
                ->orWhere('phone', '251'.$national)
                ->orWhere('phone', '0'.$national)
                ->orWhere('phone', $national))
            ->first();
    }
}

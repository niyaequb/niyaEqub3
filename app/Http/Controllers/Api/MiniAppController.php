<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DashenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Login with DBSA" — the server half of the SuperApp sign-in.
 *
 * The mini-app asks the Dashen SuperApp for a `customeridentifier` over the JS
 * bridge and POSTs it here. This exchanges it with Dashen for the customer's
 * identity and, when that maps onto an existing Niya member, issues the same
 * JWT an ordinary phone-and-password sign-in would.
 *
 * WHY THE EXCHANGE HAPPENS HERE AND NOT IN THE MINI-APP
 *
 * The identifier is a bearer of identity. If the mini-app exchanged it itself
 * it would need the app secret in browser JavaScript, where anyone can read it
 * and mint their own orders. Dashen's own sample makes the same call — the
 * callback handler posts to `https://merchant.domain/miniapp/get-identifier` —
 * for exactly this reason.
 *
 * @group Mini App
 */
class MiniAppController extends Controller
{
    /**
     * Resolve a SuperApp customer identifier into a Niya session.
     */
    public function getIdentifier(Request $request, DashenService $dashen): JsonResponse
    {
        $data = $request->validate([
            'customeridentifier' => ['required', 'string', 'max:512'],
            'appcode' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:32'],
        ]);

        // The appcode is the mini-app's own identity, not the customer's, so a
        // mismatch means the request did not come from our mini-app. Checked
        // only when sent, because the sample's callback handler is free to omit
        // it and rejecting a well-formed login over a missing optional field
        // would lock members out for no security gain.
        if (! empty($data['appcode']) && $data['appcode'] !== $dashen->miniAppCode()) {
            Log::warning('Mini-app identifier rejected: appcode mismatch', [
                'received' => $data['appcode'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unknown mini app.',
            ], 403);
        }

        $result = $dashen->exchangeCustomerIdentifier($data['customeridentifier']);

        if (! $result['success']) {
            // An unconfigured exchange is an integration gap, not a rejected
            // member. 503 says "come back later"; the mini-app falls through to
            // ordinary phone-and-OTP sign-in either way, so nobody is stranded.
            $status = ($result['unconfigured'] ?? false) ? 503 : 401;

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Could not verify the SuperApp session.',
            ], $status);
        }

        $user = $this->findUserByPhone($result['phone'] ?? null);

        if (! $user) {
            // Recognised by the bank, unknown to us. Not an error: this is a
            // first-time member, and the mini-app should send them through
            // registration with the phone pre-filled rather than showing a
            // sign-in failure for an account that was never going to exist.
            return response()->json([
                'status' => 'success',
                'registered' => false,
                'phone' => $result['phone'] ?? null,
                'message' => 'No Niya account yet for this SuperApp customer.',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'registered' => true,
            'token' => JWTAuth::fromUser($user),
            'user' => $user,
        ]);
    }

    /**
     * Match a phone number however Dashen chose to format it.
     *
     * Ethiopian numbers arrive as +251912222222, 251912222222 or 0912222222
     * depending on which system last touched them, and the users table holds
     * whichever form was typed at registration. Matching on the national
     * significant number — the nine digits after the country code or the
     * leading zero — is the one comparison that is stable across all three.
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

        // Reduce to the 9-digit national number: strip a 251 country code or a
        // trunk 0, whichever is present.
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Which banks a member can pay through.
 *
 * WHY THE CLIENT ASKS INSTEAD OF KNOWING
 *
 * Niya will collect through several banks — Dashen now, CBE, Awash and more.
 * If each app shipped its own list, adding a bank would mean an app release
 * and a wait for members to update, and removing one would leave old builds
 * offering a bank that no longer works. So the server owns the list and the
 * apps render whatever it returns.
 *
 * Only banks that can actually take money right now are listed. A bank that is
 * registered but missing credentials is withheld rather than shown and then
 * failing at the moment someone tries to pay, which would look like a broken
 * app rather than an unfinished integration.
 *
 * NOTHING SECRET IS RETURNED. Each entry says how to reach the bank — the
 * bridge object name, the public app code, the stage — not how to impersonate
 * the merchant. The signing material stays on the server.
 *
 * Public on purpose: the app draws its payment options before a member has
 * necessarily signed in, and none of this is privileged.
 *
 * @group Payments
 */
class PaymentProviderController extends Controller
{
    /**
     * List the banks available for payment.
     */
    public function index(Request $request, PaymentGatewayManager $gateways): JsonResponse
    {
        // `channel=mobile` is sent by the phone app; anything else, or nothing,
        // is the mini app and gets the list it has always had.
        $channel = $request->query('channel') === 'mobile' ? 'mobile' : null;

        $providers = $gateways->clientCatalogue(
            $channel,
            $channel === 'mobile' ? $this->viewer() : null,
        );

        return response()->json([
            'status' => 'success',
            'data' => $providers,
            'meta' => [
                'default' => config('payments.default'),
                'currency' => config('payments.currency', 'ETB'),
                // An empty list is a real state, not an error: it means no bank
                // is configured on this environment. Saying so plainly lets the
                // app show "payments are unavailable" instead of an empty
                // picker the member cannot act on.
                'count' => count($providers),
            ],
        ]);
    }

    /**
     * The signed-in member, if the request carries a valid token.
     *
     * Optional on purpose: this route is public. It only matters when a bank
     * is switched on for testers, and a missing or expired token simply means
     * "not a tester". Never throws.
     */
    protected function viewer(): mixed
    {
        try {
            return JWTAuth::getToken() ? (JWTAuth::parseToken()->authenticate() ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

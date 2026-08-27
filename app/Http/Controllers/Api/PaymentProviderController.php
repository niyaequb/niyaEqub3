<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;

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
    public function index(PaymentGatewayManager $gateways): JsonResponse
    {
        $providers = $gateways->clientCatalogue();

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
}

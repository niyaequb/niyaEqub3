<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\FabricGateway;

/**
 * Dashen Bank SuperApp — mini app payments.
 *
 * The first bank on the platform, and the reference implementation for the
 * rest. Almost everything it does is the fabric scheme, so almost all of it
 * lives in FabricGateway; what is left here is Dashen's identity and the two
 * places its integration pack is silent.
 *
 * HOW A PAYMENT ACTUALLY HAPPENS
 *
 * Not as a hosted checkout. The Niya mini app runs INSIDE the Dashen SuperApp
 * and reaches it over a JavaScript bridge, `window.dashenbanksuperapp`. This
 * class produces a signed order payload; the mini app hands it to the SuperApp
 * with `initiatePayment`; the SuperApp collects the customer's authorisation.
 * There is no URL to open and no page we control, which is why the client
 * config below describes a bridge rather than a checkout link.
 *
 * WHAT DASHEN HAS NOT SUPPLIED
 *
 * Two things, both reached through config so that filling them in is an .env
 * change rather than a code change:
 *
 *   DASHEN_ORDER_QUERY_PATH — confirms a transaction settled. Until it is set,
 *   canVerifySettlement() is false and PaymentSettlementService leaves
 *   contributions pending. That is the safe direction: an unverifiable payment
 *   showing as unreconciled is a visible problem, whereas crediting a member
 *   for money nobody confirmed is an invisible one.
 *
 *   DASHEN_TOKEN_PATH — exchanges a customeridentifier for a fabric token, and
 *   is what makes "Login with DBSA" work. Without it the app falls back to
 *   ordinary phone-and-OTP sign-in, so nobody is locked out.
 */
class DashenGateway extends FabricGateway
{
    public function slug(): string
    {
        return 'dashen';
    }
}

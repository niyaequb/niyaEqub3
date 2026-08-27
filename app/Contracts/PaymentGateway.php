<?php

namespace App\Contracts;

/**
 * One bank's payment integration.
 *
 * Niya collects contributions through several banks — Dashen now, CBE, Awash
 * and others to follow. This interface is the line between what the platform
 * needs and what each bank happens to require.
 *
 * WHAT SITS ON EACH SIDE OF THE LINE
 *
 * A gateway knows one bank's wire format: how to sign an order, how to ask
 * whether a transaction settled, what a notification from that bank looks like.
 * It knows nothing about Equb — no memberships, no contributions, no SMS
 * receipts, no commission. It is handed a reference, an amount and a narration,
 * and hands back a payload.
 *
 * Everything on the other side — resolving a reference to contributions,
 * settling them together, recalculating a member's position, sending one
 * receipt per payer — is identical whichever bank took the money, and lives in
 * PaymentSettlementService. That split is what makes the tenth bank cost a
 * class rather than a rewrite.
 *
 * WHAT A GATEWAY MUST NEVER DO
 *
 * Decide an amount. The amount is read from the membership by the controller
 * and signed by the gateway; a gateway that recalculated it would be able to
 * disagree with the figure the member saw on screen.
 *
 * Report a payment as settled on the strength of a notification alone. A
 * notification is a claim. verifyPayment() is the fact, and it must fail closed
 * — an unverifiable payment stays pending rather than crediting a member for
 * money nobody confirmed arrived.
 */
interface PaymentGateway
{
    /**
     * Stable identifier, e.g. 'dashen'.
     *
     * This is the value stored in equb_payments.payment_method and carried by
     * every historical row, so it is chosen once and never changed.
     */
    public function slug(): string;

    /** Human-readable name for admin screens and the client's bank picker. */
    public function displayName(): string;

    /**
     * Whether this gateway has everything it needs to actually take a payment.
     *
     * False when credentials are missing. A gateway that is registered but not
     * configured is hidden from the client rather than offered and then
     * failing at the moment someone tries to pay.
     */
    public function isConfigured(): bool;

    /**
     * Whether settlement can be independently confirmed with the bank.
     *
     * Separate from isConfigured() on purpose: a bank can be able to take
     * money before it has given us a way to verify it. Collecting in that
     * state is a deliberate operational choice, and the platform should be
     * able to see it rather than discover it during reconciliation.
     */
    public function canVerifySettlement(): bool;

    /**
     * Build and sign one payment order.
     *
     * @param  string  $reference  The merchant order id. For a batch this is
     *                             the shared batch reference.
     * @param  float   $amount     Total to debit, in ETB.
     * @param  string  $narration  Short description for the payer's statement.
     * @return array               The payload the client hands to the bank.
     */
    public function createOrder(string $reference, float $amount, string $narration): array;

    /**
     * Credentials the client presents alongside the order.
     *
     * @param  string|null  $sessionToken  The customer's own token, where the
     *                                     bank issues one at sign-in.
     */
    public function authPayload(?string $sessionToken = null): array;

    /**
     * Ask the bank whether a transaction actually completed.
     *
     * MUST fail closed. Returning ['success' => false, 'unconfigured' => true]
     * when verification is not wired up tells the settlement service to leave
     * contributions pending instead of failing or crediting them.
     *
     * @return array{success: bool, message?: string, data?: mixed, unconfigured?: bool}
     */
    public function verifyPayment(string $reference): array;

    /** Constant-time check of a notification signature over the raw body. */
    public function verifyNotificationSignature(string $rawBody, ?string $signature): bool;

    /**
     * Header names this bank may sign its notifications with, in priority
     * order. The first one present on the request is the one checked.
     *
     * @return array<int, string>
     */
    public function signatureHeaders(): array;

    /** Pull the merchant order reference out of a notification body. */
    public function extractReference(array $payload): ?string;

    /**
     * Exchange a host-app customer identifier for a session and an identity.
     *
     * Only meaningful where the bank hosts the app and can say who is using
     * it. Gateways without that concept return
     * ['success' => false, 'unsupported' => true].
     *
     * @return array{success: bool, token?: ?string, phone?: ?string, message?: string, unconfigured?: bool, unsupported?: bool}
     */
    public function exchangeCustomerIdentifier(string $identifier): array;

    /**
     * What the apps need in order to present this gateway.
     *
     * Deliberately excludes anything secret. The client learns how to reach
     * the bank, not how to impersonate the merchant.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array;
}

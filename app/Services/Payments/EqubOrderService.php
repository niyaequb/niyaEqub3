<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\EqubPayment;
use Illuminate\Support\Facades\Log;

/**
 * Turning contributions into a signed bank order.
 *
 * The mirror of PaymentSettlementService: that one is bank-agnostic domain
 * logic on the way in, this is bank-agnostic domain logic on the way out. Both
 * exist so the gateways can stay ignorant of Equb, and so the controllers can
 * stay ignorant of banks.
 *
 * What it adds over calling the gateway directly is the narration — the line a
 * member reads on their bank statement. That is an Equb question ("whose place
 * is this?"), not a banking one, so it does not belong in a gateway; and it is
 * the same question for every bank, so it should not be answered twice.
 */
class EqubOrderService
{
    /**
     * Sign an order for one contribution.
     *
     * @return array{success: bool, order_payload?: array, auth_payload?: array, reference?: string, message?: string}
     */
    public function createFor(EqubPayment $payment, PaymentGateway $gateway): array
    {
        return $this->build(
            $gateway,
            $payment->reference,
            (float) $payment->amount,
            $this->narrationFor($payment),
            ['equb_payment_id' => $payment->id],
        );
    }

    /**
     * Sign one order covering several contributions.
     *
     * Used when a member settles their own place and the places they hold for
     * "My Responsibility People" together. The batch reference is what the bank
     * carries, and what settlement resolves every row by.
     *
     * @param  array<int, EqubPayment>  $payments
     */
    public function createForBatch(
        array $payments,
        string $batchReference,
        float $total,
        PaymentGateway $gateway,
    ): array {
        $count = count($payments);

        return $this->build(
            $gateway,
            $batchReference,
            $total,
            $count > 1
                ? "Equb contribution for {$count} places"
                : 'Equb contribution payment',
            ['batch_reference' => $batchReference, 'contributions' => $count],
        );
    }

    /**
     * The statement line for one contribution.
     *
     * On a place held for someone with no Niya account, the useful thing to
     * read on a statement is whose place it was — the sponsor is paying for
     * several people and needs to tell the charges apart. On an ordinary
     * membership the payer is the member, so naming them tells them nothing.
     *
     * The gateway sanitises whatever comes back, and falls back if the name
     * does not survive; a name written in Amharic reaching an ASCII-only rail
     * must not be the reason a payment is refused.
     */
    protected function narrationFor(EqubPayment $payment): string
    {
        $membership = $payment->membership;

        if ($membership?->isResponsibilitySeat()) {
            return 'Equb contribution for '.($membership->displayName() ?? '');
        }

        return 'Equb contribution payment';
    }

    /**
     * @param  array<string, mixed>  $logContext
     */
    protected function build(
        PaymentGateway $gateway,
        string $reference,
        float $amount,
        string $narration,
        array $logContext = [],
    ): array {
        try {
            return [
                'success' => true,
                'provider' => $gateway->slug(),
                'order_payload' => $gateway->createOrder($reference, $amount, $narration),
                // The customer's own session token is the client's, held from
                // sign-in; the server never sees it, so it is left null here
                // and the app fills it in.
                'auth_payload' => $gateway->authPayload(),
                // Everything the app needs to present this order — which host
                // app to talk to and how.
                //
                // Sent WITH the order rather than looked up separately so the
                // client needs no per-bank code and no cache that could be
                // stale: a new bank is a config change on the server and the
                // apps present it correctly without being rebuilt. Contains
                // nothing secret, only a global object name and a public app
                // code.
                'client' => $gateway->clientConfig(),
                'reference' => $reference,
            ];
        } catch (\Throwable $e) {
            Log::error('Could not sign an Equb payment order', $logContext + [
                'gateway' => $gateway->slug(),
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            // The exception text names credentials and key sizes. It belongs in
            // the log, not in a response a member reads.
            return [
                'success' => false,
                'message' => 'Payment could not be started. Please try again.',
            ];
        }
    }
}

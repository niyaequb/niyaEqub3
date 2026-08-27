<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\EqubPayment;
use App\Services\EqubMembershipService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * Turning a bank's notification into settled contributions.
 *
 * WHY THIS IS NOT INSIDE THE GATEWAY
 *
 * None of what happens here depends on which bank took the money. Resolving a
 * reference to contributions, settling a batch together, recalculating the
 * member's position, accruing agent commission, sending one receipt per payer —
 * all of it is identical for Dashen, CBE and Awash. Putting it in the gateway
 * would mean writing it again for every bank, and the tenth copy would drift
 * from the first.
 *
 * So the gateway answers two questions — is this notification genuine, and did
 * the transaction actually settle — and this class does the rest.
 *
 * THE RULE THAT MATTERS MOST
 *
 * A notification is a claim, not a fact. Nothing here settles anything on the
 * strength of one. The gateway is asked to confirm with the bank first, and
 * when it cannot, contributions stay pending rather than being credited. An
 * unreconciled payment is a visible problem; a member credited for money that
 * never arrived is an invisible one.
 */
class PaymentSettlementService
{
    /**
     * Settle every contribution behind one bank transaction.
     *
     * @return array{success: bool, message: string, payments?: \Illuminate\Support\Collection}
     */
    public function settle(
        PaymentGateway $gateway,
        array $payload,
        ?string $signature,
        string $rawBody,
    ): array {
        // An unsigned notification is not a notification. This check is never
        // skipped for a missing secret, unlike the implementation it replaced:
        // a public, unauthenticated route that marks money as received is not
        // something to leave conditional on configuration.
        if (! $gateway->verifyNotificationSignature($rawBody, $signature)) {
            Log::warning('Settlement notification rejected: signature did not verify', [
                'gateway' => $gateway->slug(),
            ]);

            return ['success' => false, 'message' => 'Invalid notification signature'];
        }

        $reference = $gateway->extractReference($payload);

        if (! $reference) {
            Log::error('Settlement notification carried no reference', [
                'gateway' => $gateway->slug(),
                'payload' => $payload,
            ]);

            return ['success' => false, 'message' => 'Reference not found in notification'];
        }

        $payments = $this->resolve($reference);

        if ($payments->isEmpty()) {
            Log::error('Settlement notification matched no contribution', [
                'gateway' => $gateway->slug(),
                'reference' => $reference,
            ]);

            return ['success' => false, 'message' => 'Payment not found'];
        }

        Log::info('Settlement notification received', [
            'gateway' => $gateway->slug(),
            'reference' => $reference,
            'payments' => $payments->pluck('id')->all(),
        ]);

        $verification = $gateway->verifyPayment($reference);

        if (! ($verification['success'] ?? false)) {
            return $this->handleUnverified($gateway, $payments, $reference, $verification);
        }

        return $this->markSettled($payments, $reference);
    }

    /**
     * Find the contributions a reference stands for.
     *
     * Two stages, in this order: an exact match on `reference` first, and only
     * if nothing is found, on `batch_reference`. That keeps the single-payment
     * path unchanged while supporting a member who settled their own place and
     * the places they hold for other people in one charge.
     *
     * @return \Illuminate\Support\Collection<int, EqubPayment>
     */
    protected function resolve(string $reference)
    {
        $payments = EqubPayment::where('reference', $reference)->get();

        if ($payments->isEmpty()) {
            $payments = EqubPayment::where('batch_reference', $reference)->get();
        }

        return $payments;
    }

    /**
     * The bank did not confirm the charge.
     *
     * Two very different situations, and collapsing them would be a mistake:
     *
     *   Verification is not wired up — an integration gap. The charge may well
     *   have succeeded and we simply cannot ask. Leaving the rows pending
     *   keeps the money visible as unreconciled instead of writing off a real
     *   payment, which a member would experience as being charged and not
     *   credited.
     *
     *   The bank answered and said no — a failed charge. Mark it failed so the
     *   member is not shown a contribution that will never complete.
     */
    protected function handleUnverified(
        PaymentGateway $gateway,
        $payments,
        string $reference,
        array $verification,
    ): array {
        if ($verification['unconfigured'] ?? false) {
            Log::warning('Settlement left pending: cannot verify with the bank', [
                'gateway' => $gateway->slug(),
                'reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
            ]);

            return [
                'success' => false,
                'message' => 'Settlement could not be verified; contributions left pending.',
            ];
        }

        foreach ($payments as $payment) {
            if ($payment->isPending()) {
                $payment->markAsFailed();
            }
        }

        Log::warning('Settlement verification failed', [
            'gateway' => $gateway->slug(),
            'reference' => $reference,
            'reason' => $verification['message'] ?? null,
        ]);

        return [
            'success' => false,
            'message' => $verification['message'] ?? 'The charge was not successful.',
        ];
    }

    /**
     * Credit every contribution in the charge.
     *
     * They settle together. Marking only one would leave the member's own place
     * paid and the places they pay for still showing as owed, after they had
     * already been charged for all of them.
     *
     * Skipping rows that are no longer pending is what makes this idempotent:
     * a replayed or duplicated notification cannot double-settle a
     * contribution or send a second receipt.
     */
    protected function markSettled($payments, string $reference): array
    {
        $settled = collect();

        foreach ($payments as $payment) {
            if (! $payment->isPending()) {
                continue;
            }

            $payment->markAsPaid();
            $settled->push($payment);

            if ($membership = $payment->membership) {
                app(EqubMembershipService::class)->completeIfEligible($membership);
            }
        }

        if ($settled->isNotEmpty()) {
            $this->announce($settled, $reference);
        }

        return [
            'success' => true,
            'message' => 'Payment verified and processed',
            'payments' => $payments,
        ];
    }

    /**
     * One receipt per person, not per contribution.
     *
     * A member who just settled three places should get one message naming the
     * total, not three texts each quoting a third of what left their account.
     * Grouped by the payer's phone, since payerUser() resolves a held place to
     * the sponsor who actually paid for it.
     *
     * @param  \Illuminate\Support\Collection<int, EqubPayment>  $payments
     */
    protected function announce($payments, string $reference): void
    {
        $receipts = [];

        foreach ($payments as $payment) {
            $membership = $payment->membership;
            $phone = $membership?->payerUser()?->phone;

            if (! $phone) {
                continue;
            }

            $receipts[$phone] ??= ['total' => 0.0, 'held' => []];
            $receipts[$phone]['total'] += (float) $payment->amount;

            if ($membership->isResponsibilitySeat()) {
                $receipts[$phone]['held'][] = $membership->displayName();
            }
        }

        foreach ($receipts as $phone => $receipt) {
            $held = $receipt['held'] !== []
                ? ' This covers '.implode(', ', $receipt['held']).' as well as your own place.'
                : '';

            try {
                app(SmsService::class)->sendSms(
                    $phone,
                    'Your Equb payment of '.number_format($receipt['total'], 2)
                        .' ETB has been received successfully.'.$held,
                    null,
                    null
                );
            } catch (\Throwable $e) {
                // A dead SMS gateway must never undo a settled payment.
                Log::warning('Equb payment receipt SMS failed: '.$e->getMessage(), [
                    'reference' => $reference,
                ]);
            }
        }
    }
}

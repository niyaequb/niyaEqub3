<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubPayment;
use App\Services\EqubMembershipService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Cache;
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
     * How long an order the bank has never heard of stays pending.
     *
     * A member who taps Pay and then closes the app leaves a row behind that
     * Dashen have no record of — `check-status` answers "Transaction not
     * found". That is indistinguishable, in the first minutes, from a member
     * still looking at the PIN screen, so it cannot mean failure immediately.
     *
     * It does mean failure eventually. `timeout_express` on the order is 120
     * minutes, after which nobody can pay it; a day is comfortably past that
     * and leaves room for a bank-side delay nobody has warned us about. Without
     * this, abandoned orders would be re-queried every five minutes forever and
     * the pending queue an operator is meant to watch would fill with rows that
     * were never going anywhere.
     */
    protected const ABANDON_AFTER_HOURS = 24;

    /**
     * The shortest gap between two bank checks that members' reads can cause
     * for one reference.
     *
     * A member coming back from the SuperApp polls every few seconds, and so
     * may two devices, and so may a tab left open. Without a floor, every one
     * of those reads is a call into Dashen's API. Fifteen seconds keeps the
     * member's wait short while capping any single reference at four calls a
     * minute however hard it is refreshed.
     */
    protected const ON_READ_THROTTLE_SECONDS = 15;

    /**
     * The most distinct bank references a single read may ask about.
     *
     * Newest first, so the payment the member has just made is always among
     * them. Anything beyond this is left to the scheduled sweep, which is what
     * stops opening one Equb with a long tail of old pending rows turning into
     * a burst of calls.
     */
    protected const ON_READ_MAX_REFERENCES = 3;

    /**
     * How long one reference is held exclusively while it is being settled.
     *
     * Long enough to cover a fabric-token mint and a check-status call at
     * their full timeouts (20s + 30s) with room to spare. A lock that expires
     * mid-settlement would let a second worker in; one that outlives a
     * crashed worker only delays the next check by a minute.
     */
    protected const SETTLEMENT_LOCK_SECONDS = 60;

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

        // From here a notification is handled exactly like any other check:
        // under the reference's lock, with the rows re-read inside it, asking
        // the bank to confirm. So a notification can never race the sweep, a
        // member's read or the operator's button into settling one payment
        // twice. It waits briefly for a check already under way, because a
        // notification arrives once and its answer is worth having.
        return $this->reconcile($gateway, $reference, 10);
    }

    /**
     * Settle a reference by ASKING the bank, with no notification involved.
     *
     * WHY THERE IS NO SIGNATURE CHECK HERE
     *
     * settle() begins by authenticating a message that arrived unprompted from
     * the outside; anyone can POST to that route, so the signature is the only
     * thing separating a bank from an attacker. This method starts the other
     * way round: WE call the bank, over TLS, with our own credentials, and
     * read the reply to a question we asked. There is no untrusted inbound
     * message to authenticate — the trust comes from having made the call.
     *
     * That is what makes settlement possible without a webhook. Dashen send
     * nothing unprompted, so the client callback becomes a trigger to ask, and
     * ReconcilePendingPayments sweeps up everything whose callback never
     * arrived because a member closed the app or lost signal.
     *
     * Everything after the question is identical to the notification path:
     * same resolution, same verification, same crediting, same one-receipt-
     * per-payer. Only the way the news reaches us differs.
     *
     * @return array{success: bool, message: string, payments?: \Illuminate\Support\Collection}
     */
    public function reconcile(PaymentGateway $gateway, string $reference, int $waitSeconds = 0): array
    {
        // ONE SETTLEMENT PER REFERENCE AT A TIME.
        //
        // There are now three ways into this method — the five-minute sweep,
        // a member's read (verifyPendingAfterResponse) and the operator's
        // "Check with the bank" button — and nothing stops two of them landing
        // on the same reference in the same second. Both would read the rows
        // as pending, both would mark them paid, and the second update would
        // still count as a status change: a second agent commission and a
        // second SMS receipt for one payment.
        //
        // Non-blocking by default: the sweep and a member's read can simply
        // move on, because whoever holds the lock will settle the rows and the
        // next read sees the result. A caller that needs the ANSWER — the
        // double-charge guard, deciding whether to take a second payment —
        // passes $waitSeconds and waits its turn instead. `locked` tells it
        // apart from a real "not paid".
        $lock = Cache::lock('settlement:'.$reference, static::SETTLEMENT_LOCK_SECONDS);

        try {
            $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            return [
                'success' => false,
                'locked' => true,
                'message' => 'A check for this payment is already in progress.',
            ];
        }

        try {
            return $this->reconcileLocked($gateway, $reference);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ask the bank about a member's recent pending contributions, after the
     * response to the member has already been sent.
     *
     * WHY THIS EXISTS
     *
     * Until now the only thing that ever confirmed a payment was the
     * five-minute sweep. A member who paid, came straight back and opened
     * their Equb was shown the contribution as not paid — no history entry,
     * progress at 0%, not eligible for the draw — for up to five minutes, and
     * indefinitely if the scheduler was not running. Dashen's QA reported
     * exactly that, from four different angles.
     *
     * This closes the window from the member's side: reading a pending
     * contribution becomes the trigger to ask. The answer lands on the NEXT
     * read, a few seconds later, which is why the app polls after a payment
     * rather than reading once.
     *
     * WHY AFTER THE RESPONSE
     *
     * A check-status call can take up to thirty seconds when the bank is slow,
     * and the member is looking at a loading screen while it does. Running it
     * as a terminating callback means the screen answers instantly from what
     * the database knows now, and the bank is asked once the member already
     * has that answer. Under PHP-FPM this runs after fastcgi_finish_request(),
     * so it holds a worker but never the member.
     *
     * WHAT KEEPS IT FROM HURTING ANYONE
     *
     *   - Only pending, bank-collected rows younger than the abandonment
     *     window. Nothing paid, nothing failed, nothing offline, nothing old.
     *   - At most ON_READ_MAX_REFERENCES distinct references per read,
     *     newest first.
     *   - At most one check per reference per ON_READ_THROTTLE_SECONDS, taken
     *     atomically with Cache::add so concurrent reads cannot both win.
     *   - reconcile() itself holds a per-reference lock, so this can never
     *     race the sweep into settling a payment twice.
     *
     * Never throws. A failure to ask is logged and the row stays pending,
     * exactly as it would have without this method.
     *
     * @param  iterable<int, EqubPayment>  $payments
     * @return int  How many references were queued for a check.
     */
    public function verifyPendingAfterResponse(iterable $payments): int
    {
        // Terminating callbacks run AFTER the response only where the SAPI can
        // finish a request early: PHP-FPM (production here) and LiteSpeed.
        // Anywhere else — `php artisan serve`, mod_php — they run BEFORE the
        // response is flushed, and the member's screen would wait on the bank.
        // There, do nothing: the five-minute sweep still confirms payments,
        // and a slow read is a worse bug than a slower confirmation.
        if (! function_exists('fastcgi_finish_request') && ! function_exists('litespeed_finish_request')) {
            return 0;
        }

        try {
            $cutoff = now()->subHours(static::ABANDON_AFTER_HOURS);
            $gateways = app(PaymentGatewayManager::class);

            $references = collect($payments)
                ->filter(fn ($payment) => $payment instanceof EqubPayment
                    && $payment->status === EqubPaymentStatus::Pending
                    && $payment->payment_method?->isGateway()
                    && $payment->created_at !== null
                    && $payment->created_at->gte($cutoff))
                ->sortByDesc(fn (EqubPayment $payment) => $payment->created_at)
                ->mapWithKeys(fn (EqubPayment $payment) => [
                    (string) ($payment->batch_reference ?: $payment->reference) => $payment->payment_method->value,
                ])
                ->filter(fn ($slug, $reference) => $reference !== '')
                ->take(static::ON_READ_MAX_REFERENCES);

            $queued = 0;

            foreach ($references as $reference => $slug) {
                $gateway = $gateways->tryGet($slug);

                if (! $gateway) {
                    continue;
                }

                // Atomic: of any number of simultaneous reads, exactly one
                // gets to ask the bank about this reference in this window.
                if (! Cache::add('settlement:on-read:'.$reference, true, static::ON_READ_THROTTLE_SECONDS)) {
                    continue;
                }

                app()->terminating(function () use ($gateway, $reference): void {
                    try {
                        $result = $this->reconcile($gateway, (string) $reference);

                        Log::info('On-read settlement check', [
                            'gateway' => $gateway->slug(),
                            'reference' => $reference,
                            'success' => $result['success'] ?? false,
                            'message' => $result['message'] ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('On-read settlement check failed', [
                            'gateway' => $gateway->slug(),
                            'reference' => $reference,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });

                $queued++;
            }

            return $queued;
        } catch (\Throwable $e) {
            // The read that called this must still succeed. A member who
            // cannot open their Equb because a cache table is missing would be
            // a far worse bug than the one this method fixes.
            Log::warning('Could not queue on-read settlement checks', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * The body of reconcile(), run only while holding the reference's lock.
     *
     * @return array{success: bool, message: string, payments?: \Illuminate\Support\Collection}
     */
    protected function reconcileLocked(PaymentGateway $gateway, string $reference): array
    {
        $payments = $this->resolve($reference);

        if ($payments->isEmpty()) {
            return ['success' => false, 'message' => 'Payment not found'];
        }

        // Nothing left to do, and worth answering before spending a call on
        // the bank: a sweep runs over the same rows repeatedly.
        //
        // ALREADY PAID, not merely "no longer pending". A row this service
        // previously marked FAILED is exactly the one worth asking about
        // again — a write-off made on a status nobody had confirmed the
        // meaning of, or on a bank that was unreachable, is a mistake that
        // should be recoverable rather than permanent. Skipping every
        // non-pending row would have made it permanent.
        if ($payments->every(fn (EqubPayment $payment) => $payment->status === EqubPaymentStatus::Paid)) {
            return [
                'success' => true,
                'message' => 'Already settled',
                'payments' => $payments,
            ];
        }

        $verification = $gateway->verifyPayment($reference);

        if (! ($verification['success'] ?? false)) {
            return $this->handleUnverified($gateway, $payments, $reference, $verification);
        }

        return $this->markSettled(
            $payments,
            $reference,
            $gateway->extractSettlement((array) ($verification['data'] ?? []))
        );
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
     * THREE situations, and collapsing any two of them would be a mistake:
     *
     *   `unconfigured` — verification is not wired up at all. An integration
     *   gap. The charge may well have succeeded and we simply cannot ask.
     *
     *   `pending` — we asked and got no clear answer: the bank was
     *   unreachable, the token could not be minted, or the status it returned
     *   is one nobody has told us the meaning of yet. Not evidence of failure.
     *
     *   Neither flag — the bank answered and said no. A failed charge. Mark it
     *   failed so the member is not shown a contribution that will never
     *   complete.
     *
     * The first two leave the rows pending, which keeps the money visible as
     * unreconciled instead of writing off a real payment — something a member
     * experiences as being charged and not credited, and which they have no
     * way to argue with. An operator looking at a stuck row is the cheaper
     * mistake by a wide margin, so anything short of an explicit "no" from the
     * bank lands here.
     */
    protected function handleUnverified(
        PaymentGateway $gateway,
        $payments,
        string $reference,
        array $verification,
    ): array {
        if (($verification['unconfigured'] ?? false) || ($verification['pending'] ?? false)) {
            // An order the bank has never heard of, long after anyone could
            // still pay it, was abandoned rather than left in doubt. Writing it
            // off here is safe in a way that writing off an unrecognised STATUS
            // is not: "not found" means no money moved, so there is no member
            // who has been debited and is about to be told nothing happened.
            if (($verification['not_found'] ?? false) && $this->isAbandoned($payments)) {
                foreach ($payments as $payment) {
                    if ($payment->isPending()) {
                        $payment->markAsFailed();
                    }
                }

                Log::info('Abandoned order marked failed: never completed at the bank', [
                    'gateway' => $gateway->slug(),
                    'reference' => $reference,
                    'payments' => $payments->pluck('id')->all(),
                ]);

                return [
                    'success' => false,
                    'message' => 'This payment was never completed.',
                ];
            }

            Log::warning('Settlement left pending: cannot verify with the bank', [
                'gateway' => $gateway->slug(),
                'reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
            ]);

            // Carried through so a caller can tell "the bank has never heard
            // of this order" (no money moved) from "the bank did not give a
            // clear answer" (money may be moving). The double-charge guard
            // treats those two very differently.
            return [
                'success' => false,
                'pending' => true,
                'not_found' => (bool) ($verification['not_found'] ?? false),
                'unconfigured' => (bool) ($verification['unconfigured'] ?? false),
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
     * Is every contribution here old enough to give up on?
     *
     * Every, not any. A batch settles together, so if one row in it is still
     * young the whole charge is still young — and writing off the older rows
     * beside it would split a single payment into a half-failed state that
     * nothing downstream expects.
     *
     * @param  \Illuminate\Support\Collection<int, EqubPayment>  $payments
     */
    protected function isAbandoned($payments): bool
    {
        $cutoff = now()->subHours(static::ABANDON_AFTER_HOURS);

        return $payments->every(
            fn (EqubPayment $payment): bool => $payment->created_at !== null
                && $payment->created_at->lt($cutoff)
        );
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
    protected function markSettled($payments, string $reference, array $settlement = []): array
    {
        $settled = collect();

        foreach ($payments as $payment) {
            // Skip what is ALREADY PAID, which is what keeps this idempotent:
            // a replayed notification cannot double-credit a contribution or
            // send a second receipt.
            //
            // A FAILED row is not skipped. If the bank now says the money
            // moved, the bank is right and our earlier write-off was wrong —
            // and a member who really paid must end up credited, whatever this
            // service concluded on an earlier pass with worse information.
            if ($payment->status === EqubPaymentStatus::Paid) {
                continue;
            }

            // The same settlement on every row in a batch, which is correct:
            // one bank transaction paid for all of them, and every row should
            // carry the transaction id somebody will search for.
            $payment->markAsPaid($settlement);
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

<?php

namespace App\Http\Controllers\Api\Member;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\StoreEqubPaymentRequest;
use App\Http\Resources\Api\EqubPaymentResource;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use App\Services\Payments\EqubOrderService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Contribution collection for members.
 *
 * Contributions are collected through a bank. Which bank is the caller's choice from the
 * list at GET /api/payments/providers — Dashen today, CBE and Awash to follow — and this
 * controller names none of them: it asks PaymentGatewayManager what is available and
 * EqubOrderService to sign the order.
 *
 * A record is created in the `pending` state together with a signed order payload, and
 * becomes `paid` only once the bank's settlement notification has been received AND
 * independently confirmed with that bank. Neither the 201 response nor the bank app's own
 * callback into the client is proof of settlement — the client must poll `show`.
 *
 * Scope follows the money: a member sees and pays their own contributions and every
 * contribution on a place they sponsor for someone without an account.
 *
 * @group Member · Contributions
 * @authenticated
 */
class EqubPaymentController extends Controller
{
    /**
     * List payments for current user's memberships.
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        // A member's payments are their own plus the ones on places they hold
        // for someone else in a Group Equb. A responsibility seat has no
        // member_id, so filtering on that alone hid every contribution the
        // sponsor had actually made.
        $query = EqubPayment::query()
            ->whereHas('membership', fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->with(['membership.member.user', 'membership.sponsor.user', 'membership.equbGroup.package']);

        if ($request->filled('equb_membership_id')) {
            $query->where('equb_membership_id', $request->input('equb_membership_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // "What did I just try to pay?"
        //
        // Asked by the mini app when it starts up signed in through the
        // SuperApp rather than from its own storage — the case where the
        // SuperApp's reload after a payment wiped that storage, and with it
        // the app's note of which Equb the member was paying from. The server
        // still knows, so the app can reopen that Equb and carry on confirming
        // the payment (Dashen QA, item 9). Newest attempt first; the window is
        // capped so this can never be used to page through history by age.
        $recentMinutes = $request->filled('recent_minutes')
            ? max(1, min(60, (int) $request->input('recent_minutes')))
            : null;

        if ($recentMinutes !== null) {
            $query->where('created_at', '>=', now()->subMinutes($recentMinutes));
        }

        $payments = ($recentMinutes !== null
            ? $query->latest('created_at')
            : $query->latest('payment_date')
        )->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubPaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Show a single payment (only if it belongs to current user's membership).
     */
    public function show(Request $request, EqubPayment $equbPayment): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }
        // isPayableBy() resolves to the member on a normal membership and to
        // the sponsor on a place held for someone else, so both can read their
        // own receipts and neither can read anybody else's.
        if (! $equbPayment->membership?->isPayableBy($member->id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $equbPayment->load(['membership.member.user', 'membership.sponsor.user', 'membership.equbGroup.package']);

        // The class docblock tells clients to poll this to confirm settlement,
        // and until now polling it confirmed nothing: it only re-read the row.
        // A pending contribution is now checked with the bank once this
        // response has gone, so the next poll carries the bank's answer.
        app(PaymentSettlementService::class)->verifyPendingAfterResponse([$equbPayment]);

        return response()->json([
            'status' => 'success',
            'data' => new EqubPaymentResource($equbPayment),
        ]);
    }


    /**
     * A bank's settlement notification.
     *
     * One route serves every bank; `{provider}` says which. Signature-verified,
     * no bearer token.
     *
     * Resolution is delegated wholesale to PaymentSettlementService rather than
     * being half-done here. The implementation this replaced looked the payment
     * up by `reference` before handing over, which meant a batch notification —
     * whose reference is a `batch_reference` — was rejected with a 404 before
     * the handler that knows how to resolve it ever ran. One place decides what
     * a reference means.
     *
     * The RAW body is passed through for the HMAC. Re-encoding `$request->all()`
     * would change key order and whitespace and break a valid signature.
     */
    public function notification(
        Request $request,
        string $provider,
        PaymentGatewayManager $gateways,
        PaymentSettlementService $settlement,
    ): JsonResponse {
        $gateway = $gateways->tryGet($provider);

        if (! $gateway) {
            // 404, not 400: nothing about this request is retryable, and a bank
            // posting to a provider we do not run is a routing mistake on one
            // side or the other that someone needs to see.
            Log::warning('Settlement notification for an unknown provider', [
                'provider' => $provider,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Unknown payment provider.'], 404);
        }

        $payload = $request->all();

        Log::info("Received {$provider} settlement notification: ".json_encode($payload));

        // Banks disagree about what to call this header, and rename it without
        // warning. Each gateway lists the names it may use; the first present
        // is the one checked.
        $signature = null;
        foreach ($gateway->signatureHeaders() as $header) {
            if ($value = $request->header($header)) {
                $signature = $value;
                break;
            }
        }

        try {
            $result = $settlement->settle(
                $gateway,
                $payload,
                $signature,
                $request->getContent(),
            );

            // Another check held this payment for longer than the
            // notification waited, so it was never processed. 503 asks the
            // bank to deliver it again rather than treating it as done.
            if ($result['locked'] ?? false) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Busy, please retry.',
                ], 503);
            }

            // 200 even when the charge itself failed: the notification was
            // handled correctly, and a non-200 tells the bank to retry a
            // delivery that has nothing left to do. Settlement is never
            // inferred from this status code.
            return response()->json([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['message'] ?? 'Notification processed.',
            ]);
        } catch (\Throwable $e) {
            // A 500 here is a real processing failure and the bank should retry.
            Log::error("{$provider} notification processing failed: ".$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Record a contribution and, for the gateway method, sign a Dashen order.
     */
    public function store(StoreEqubPaymentRequest $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        // Either their own membership, or a place they are responsible for.
        // The second case is the whole point of "My Responsibility People":
        // the seat has nobody behind it to pay, so the sponsor pays it.
        $membership = EqubMembership::query()
            ->where('id', $request->input('equb_membership_id'))
            ->where(fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->first();

        if (! $membership) {
            return response()->json(['status' => 'error', 'message' => 'Membership not found or access denied.'], 404);
        }

        $method = $request->input('payment_method');
        $amount = (float) $request->input('amount');
        $paymentDate = $request->input('payment_date');

        $paymentMethod = EqubPaymentMethod::from($method);

        if ($paymentMethod->isSelectable()) {
            $gateways = app(PaymentGatewayManager::class);
            $gateway = $gateways->get($method);

            // The charge is signed server-side, so the signed amount had better
            // be the amount that is actually owed. The client still sends one —
            // it is what the member saw on the confirmation screen — but a
            // disagreement is refused rather than quietly resolved either way.
            // Silently overriding would charge a different figure from the one
            // on screen; silently accepting would let the client set the price.
            $due = round((float) $membership->contribution_amount, 2);

            if ($due <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This contribution has no amount set.',
                ], 422);
            }

            if (round($amount, 2) !== $due) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That is not the amount due on this contribution.',
                ], 422);
            }

            // ONE PAYMENT PER ROUND. See roundStatus().
            $round = $this->roundStatus(collect([$membership]), $paymentDate);

            if ($round !== 'clear') {
                return $this->roundBlocked($round, false);
            }

            $payment = EqubPayment::create([
                'equb_membership_id' => $membership->id,
                'amount' => $due,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'status' => EqubPaymentStatus::Pending,
            ]);

            $result = app(EqubOrderService::class)->createFor(
                $payment,
                $gateway,
                $request->input('customer_identifier'),
            );

            if (! ($result['success'] ?? false)) {
                // Nothing was charged, so the pending row must not be left
                // behind looking like money the member still owes. Same rule
                // the batch path has always followed.
                $payment->delete();

                return response()->json([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Failed to initiate payment.',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated. Confirm it in the '.$gateway->displayName().' app.',
                'data' => new EqubPaymentResource($payment->load(['membership.equbGroup.package'])),
                'provider' => $gateway->slug(),
                // Handed straight to the bank's bridge as the `orderPayload` of
                // an initiatePayment call. Opaque to the client: it is signed,
                // and altering any field invalidates it.
                'order_payload' => $result['order_payload'],
                'auth_payload' => $result['auth_payload'],
                'client' => $result['client'],
                'reference' => $result['reference'],
            ], 201);
        }

        // Unreachable in practice — StoreEqubPaymentRequest only accepts
        // enabled banks — and deliberately a refusal rather than a fallback.
        //
        // What used to stand here created the contribution and immediately
        // marked it PAID, because offline and manual involved no bank and had
        // nothing to wait for. Those methods are withdrawn, and with them the
        // only route by which this endpoint could record money as received
        // without a bank confirming it. If validation and this enum ever drift
        // apart, the safe direction is to refuse, not to settle.
        return response()->json([
            'status' => 'error',
            'message' => 'That payment method is no longer available.',
        ], 422);
    }

    /**
     * Can this round be charged, for these places?
     *
     * WHY THIS EXISTS
     *
     * Nothing used to stop a second charge for a round that was already paid.
     * While settlement lagged behind the member, a round they had just paid
     * went on showing as unpaid, and paying it again debited them twice: the
     * transaction dispute Dashen Bank raised as a go-live risk.
     *
     * THE THREE ANSWERS
     *
     *   'paid'      A contribution for this round is already settled. Refuse.
     *
     *   'in_doubt'  An earlier attempt may still be moving money: the bank
     *               knows the order but has not called it either way, the
     *               bank could not be reached, or another worker is checking
     *               it right now. Refuse for now — the member is told to wait
     *               a moment — because charging again here is exactly how a
     *               double debit happens. Only for attempts younger than the
     *               order's own 120-minute expiry; past that the bank cannot
     *               take the money any more.
     *
     *   'clear'     Nothing paid, and every earlier attempt is either unknown
     *               to the bank (no money moved) or declined. A cancelled
     *               attempt must not lock the member out, so this proceeds.
     *
     * COST
     *
     * An ordinary first payment is one indexed query. The bank is asked only
     * when there is an earlier pending attempt for the same round — the
     * double-payment case — and then about each distinct attempt ONCE, however
     * many places a batch covers, newest first and at most two.
     *
     * @param  \Illuminate\Support\Collection<int, EqubMembership>  $memberships
     * @return 'paid'|'in_doubt'|'clear'
     */
    protected function roundStatus($memberships, ?string $paymentDate): string
    {
        if (blank($paymentDate) || $memberships->isEmpty()) {
            return 'clear';
        }

        try {
            $day = \Illuminate\Support\Carbon::parse($paymentDate)->toDateString();
        } catch (\Throwable) {
            return 'clear';
        }

        $ids = $memberships->pluck('id')->all();

        $forRound = fn () => EqubPayment::query()
            ->whereIn('equb_membership_id', $ids)
            ->whereDate('payment_date', $day);

        if ($forRound()->where('status', EqubPaymentStatus::Paid)->exists()) {
            return 'paid';
        }

        // Distinct bank orders, newest first. A batch is one order however
        // many places it covered, so it is asked about once.
        $attempts = $forRound()
            ->where('status', EqubPaymentStatus::Pending)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->get()
            ->groupBy(fn (EqubPayment $p) => (string) ($p->batch_reference ?: $p->reference))
            ->filter(fn ($rows, $reference) => $reference !== '')
            ->take(2);

        if ($attempts->isEmpty()) {
            return 'clear';
        }

        $gateways = app(PaymentGatewayManager::class);
        $settlement = app(PaymentSettlementService::class);
        $inDoubt = false;

        foreach ($attempts as $reference => $rows) {
            // Past the order's own expiry the bank can no longer take this
            // money, whatever else is true of it.
            $live = $rows->contains(fn (EqubPayment $p) => $p->created_at?->gte(now()->subMinutes(120)));

            $gateway = $gateways->tryGet((string) $rows->first()->payment_method?->value);

            if (! $gateway) {
                $inDoubt = $inDoubt || $live;

                continue;
            }

            try {
                // Waits for a check already under way rather than reading its
                // lock as "not paid", which is the gap a second charge would
                // otherwise slip through.
                $result = $settlement->reconcile($gateway, (string) $reference, 12);
            } catch (\Throwable $e) {
                Log::warning('Could not check an earlier attempt before a new payment', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
                $inDoubt = $inDoubt || $live;

                continue;
            }

            if ($result['success'] ?? false) {
                continue; // settled just now; the query below sees it
            }

            // "Unconfigured" is not doubt about THIS payment: this server
            // simply cannot ask the bank anything. Treating it as doubt would
            // refuse every retry after a cancelled attempt, for two hours,
            // with nothing able to clear it.
            $unclear = ($result['locked'] ?? false)
                || (($result['pending'] ?? false)
                    && ! ($result['not_found'] ?? false)
                    && ! ($result['unconfigured'] ?? false));

            $inDoubt = $inDoubt || ($unclear && $live);
        }

        if ($forRound()->where('status', EqubPaymentStatus::Paid)->exists()) {
            return 'paid';
        }

        return $inDoubt ? 'in_doubt' : 'clear';
    }

    /**
     * The refusal for a round that cannot be charged right now.
     */
    protected function roundBlocked(string $round, bool $batch): JsonResponse
    {
        $message = $round === 'paid'
            ? ($batch
                ? 'One or more of these contributions has already been paid. Refresh and try again.'
                : 'This contribution has already been paid.')
            : 'Your last payment for this date is still being confirmed by the bank. Please wait a minute and check again before paying.';

        return response()->json([
            'status' => 'error',
            'code' => $round === 'paid' ? 'already_paid' : 'payment_in_progress',
            'message' => $message,
        ], 409);
    }

    /**
     * Settle several contributions in one go.
     *
     * A member who holds places for "My Responsibility People" owes one
     * contribution per place, every round. Each place keeps its own payment
     * row — the ledger, the schedule and draw eligibility all count per place
     * — but the member should go through checkout once, for the total.
     *
     * The rows are created up front and tied together by batch_reference; the
     * SuperApp sees a single charge against that reference as its
     * merch_order_id, and the settlement notification marks every row carrying
     * it as paid together.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $member = $request->user()?->member;

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $gateways = app(PaymentGatewayManager::class);
        $accepted = $gateways->acceptedMethods();

        $data = $request->validate([
            'equb_membership_ids' => ['required', 'array', 'min:1', 'max:20'],
            'equb_membership_ids.*' => ['integer'],
            // See StoreEqubPaymentRequest for why this is optional and why it
            // is constrained: the bank's access token for this order is minted
            // from it, and nothing is authorised on its strength.
            'customer_identifier' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@:-]+$/'],
            'payment_date' => ['required', 'date'],
            // Optional here, unlike the single path: omitting it means the
            // platform default. Validated against the same live register, so a
            // bank that is not currently payable is refused before any row is
            // created.
            'payment_method' => ['nullable', \Illuminate\Validation\Rule::in($accepted)],
        ], [
            // Laravel's default for this rule is "The selected payment method
            // is invalid." — which names neither what was sent nor what would
            // have been accepted, and left this exact failure unexplainable
            // from the screen it appeared on.
            //
            // Two completely different faults produce it, and they need
            // opposite fixes, so the message has to tell them apart:
            //
            //   * the accepted list is EMPTY — no bank is configured on THIS
            //     server, so every value fails including the correct one. Fix
            //     the server's credentials.
            //   * the list is NOT empty — the client sent something outside
            //     it, which in practice means an app build older than the bank
            //     it is trying to pay through. Rebuild the client.
            //
            // Naming the accepted banks is safe: the same list is already
            // public at GET /api/payments/providers, because the apps need it
            // to draw a bank picker.
            'payment_method.in' => $accepted === []
                ? 'No bank is available for payments on this server right now — its payment credentials are missing or incomplete.'
                : 'That payment method is not available. Payments can currently go through: '.implode(', ', $accepted).'.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['equb_membership_ids'])));

        $memberships = EqubMembership::query()
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->get();

        // All or nothing. A partial batch would either charge for places the
        // member did not choose or quietly skip one they did — both make the
        // amount on the confirmation screen a lie.
        if ($memberships->count() !== count($ids)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some of those contributions are not yours to pay.',
            ], 403);
        }

        // Each place is charged its own contribution, read from the membership
        // rather than from the request: the client never gets to say what
        // something costs.
        $total = round((float) $memberships->sum(fn (EqubMembership $m): float => (float) $m->contribution_amount), 2);

        if ($total <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'These contributions have no amount set.',
            ], 422);
        }

        // No provider named means the platform's default bank. Every current
        // client names one; this is a compatibility floor, not a routing
        // decision.
        $method = $data['payment_method'] ?? (string) config('payments.default');

        $paymentMethod = EqubPaymentMethod::tryFrom($method);

        if (! $paymentMethod) {
            return response()->json([
                'status' => 'error',
                'message' => 'That payment method is not available.',
            ], 422);
        }

        if (! $paymentMethod->isSelectable()) {
            return response()->json([
                'status' => 'error',
                'message' => 'That payment method is no longer available.',
            ], 422);
        }

        $gateway = $gateways->tryGet($method);

        if (! $gateway) {
            // The method is a known bank but not one we can currently take
            // money through — usually credentials missing on this environment.
            // Refused before any row is created, so nothing is left pending
            // against a bank that was never going to be asked.
            return response()->json([
                'status' => 'error',
                'message' => 'That bank is not available for payments right now.',
            ], 422);
        }

        // ONE PAYMENT PER ROUND, for every place in the batch. All or nothing,
        // like the ownership check above: quietly dropping a place that is
        // already paid would charge a different total from the one the member
        // confirmed.
        $round = $this->roundStatus($memberships, $data['payment_date']);

        if ($round !== 'clear') {
            return $this->roundBlocked($round, true);
        }

        $batchReference = 'EQUB-B'.strtoupper(Str::random(10));

        try {
            $payments = DB::transaction(function () use ($memberships, $data, $paymentMethod, $batchReference) {
                return $memberships->map(fn (EqubMembership $m): EqubPayment => EqubPayment::create([
                    'equb_membership_id' => $m->id,
                    'amount' => (float) $m->contribution_amount,
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $paymentMethod,
                    'status' => EqubPaymentStatus::Pending,
                    'batch_reference' => $batchReference,
                ]));
            });
        } catch (\Throwable $e) {
            Log::error('Batch Equb payment could not be created: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Could not start this payment. Please try again.',
            ], 500);
        }

        $result = app(EqubOrderService::class)
            ->createForBatch(
                $payments->all(),
                $batchReference,
                $total,
                $gateway,
                $data['customer_identifier'] ?? null,
            );

        if (! ($result['success'] ?? false)) {
            // Nothing was charged, so the pending rows must not be left behind
            // looking like money the member still owes.
            $this->discardBatch($payments);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Failed to initiate payment.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment initiated. Confirm it in the '.$gateway->displayName().' app.',
            'data' => EqubPaymentResource::collection($payments),
            'provider' => $gateway->slug(),
            'order_payload' => $result['order_payload'],
            'auth_payload' => $result['auth_payload'],
            'client' => $result['client'],
            'reference' => $batchReference,
            // The authoritative sum to debit. Signed into the order, so the
            // client cannot re-add the individual amounts and arrive somewhere
            // else.
            'total_amount' => $total,
            'contributions' => $payments->count(),
        ], 201);
    }

    /** Rolls a batch back when the gateway never accepted it. */
    protected function discardBatch(\Illuminate\Support\Collection $payments): void
    {
        foreach ($payments as $payment) {
            $payment->delete();
        }
    }

    // The two receipt helpers that stood here are gone with offline and manual
    // collection. They fired the moment a contribution was recorded, which was
    // right when recording one meant cash had already changed hands.
    //
    // Nothing on this controller settles a contribution any more, so nothing
    // here should be telling a member their money has been received. That
    // message now belongs to exactly one place — PaymentSettlementService,
    // after the bank has confirmed — and it sends one receipt per payer rather
    // than one per contribution.
}

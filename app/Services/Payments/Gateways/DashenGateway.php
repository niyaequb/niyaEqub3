<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\FabricGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dashen Bank SuperApp — mini app payments.
 *
 * The first bank on the platform, and the reference implementation for the
 * rest. Almost everything it does is the fabric scheme, so almost all of it
 * lives in FabricGateway; what is left here is Dashen's identity and the one
 * place their API leaves the scheme behind.
 *
 * HOW A PAYMENT ACTUALLY HAPPENS
 *
 * Not as a hosted checkout. The Niya mini app runs INSIDE the Dashen SuperApp
 * and reaches it over a JavaScript bridge, `window.dashenbanksuperapp`. This
 * class produces a signed order payload; the mini app hands it to the SuperApp
 * with `initiatePayment`; the SuperApp collects the customer's authorisation.
 * There is no URL to open and no page we control.
 *
 * WHAT DASHEN STILL HAVE NOT SUPPLIED
 *
 * A settlement notification. There is no webhook: nothing from Dashen ever
 * arrives unprompted, so nothing tells this server that money moved.
 *
 * That used to mean manual reconciliation. It no longer does — `check-status`
 * (below) lets us ask. The client callback is the trigger and a scheduled
 * sweep catches the rest; see ReconcilePendingPayments. A webhook would still
 * be cheaper than polling, but it is now an optimisation rather than the thing
 * the integration is waiting for.
 */
class DashenGateway extends FabricGateway
{
    public function slug(): string
    {
        return 'dashen';
    }

    /**
     * Ask Dashen whether a transaction actually completed.
     *
     * WHY THIS IS OVERRIDDEN RATHER THAN CONFIGURED
     *
     * FabricGateway::verifyPayment() builds the fabric scheme — timestamp,
     * nonce_str, method, version, biz_content, an RSA `sign` and an HMAC
     * `confirmpayload` — because that is what `createorder` takes.
     *
     * `check-status` takes none of it:
     *
     *     POST {BASE_URL}/v2.0/chatbirrapi/miniapps/apps/check-status
     *     x-api-key:      {app secret}
     *     x-access-token: {fabric token}
     *     { "order_id": "...", "stage": "uat", "isFuelPayment": false }
     *
     * Three plain fields, no signing at all, and unlike `createorder` it wants
     * the fabric token as well as the api key. Bending the parent method into
     * that shape with configuration would have left a method that reads like
     * the fabric scheme and is not one.
     *
     * THE TOKEN HAS NO CUSTOMER
     *
     * Verification runs from a scheduled job, long after the member has put
     * their phone away, so there is nobody to mint a customer token for.
     * Dashen answer a `getfabrictoken` call with no `customeridentifier` with
     * a service token — "Super Admin" — and that is what is used here. It is
     * why this needs no per-payment identifier stored anywhere.
     *
     * FAILING CLOSED, IN BOTH DIRECTIONS
     *
     * Three outcomes, not two, and the third is the one that matters:
     *
     *   settled            -> credit it
     *   a status we KNOW   -> mark it failed
     *   is terminal
     *   anything else      -> LEAVE IT PENDING
     *
     * Dashen have not yet told us the full list of statuses `check-status` can
     * return. Treating an unrecognised one as failure would mark a live
     * payment dead while the money was still moving — a member debited and
     * shown nothing. Treating it as pending costs an operator a look. Those
     * are not comparable mistakes, so the unknown case is pending and stays
     * pending until somebody confirms what the value means.
     *
     * @return array{success: bool, message?: string, data?: mixed, unconfigured?: bool, pending?: bool}
     */
    public function verifyPayment(string $reference): array
    {
        if (! $this->canVerifySettlement()) {
            Log::warning('Settlement verification skipped: order query endpoint not configured', [
                'gateway' => $this->slug(),
                'reference' => $reference,
            ]);

            return [
                'success' => false,
                'unconfigured' => true,
                'message' => $this->displayName().' order verification endpoint is not configured.',
            ];
        }

        // A token failure is a problem with US reaching THEM. It says nothing
        // about whether the customer paid, so it must never mark a payment
        // failed — hence pending rather than a bare false.
        try {
            $token = $this->fabricToken();
        } catch (\Throwable $e) {
            Log::error('Could not mint a token to verify a payment', [
                'gateway' => $this->slug(),
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'pending' => true,
                'message' => 'Could not authenticate with '.$this->displayName().' to verify this payment.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->appSecret(),
                'x-access-token' => $token,
            ])->timeout(30)->post($this->endpoint('ORDER_QUERY_PATH'), [
                'order_id' => $reference,
                'stage' => $this->stage(),
                // Dashen use one endpoint for fuel payments too, where it
                // changes how the transaction is read. Never true for Equb
                // contributions; exposed as a setting only so a future
                // service does not need a code change.
                'isFuelPayment' => filter_var(
                    $this->setting('IS_FUEL_PAYMENT', 'false'),
                    FILTER_VALIDATE_BOOL
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment verification could not reach the bank', [
                'gateway' => $this->slug(),
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'pending' => true,
                'message' => 'Could not reach '.$this->displayName().' to verify this payment.',
            ];
        }

        $data = $response->json();

        // data.transactionStatus is where Dashen put it. The flatter spellings
        // behind it are what the fabric scheme uses and cost nothing to keep,
        // in case a later revision moves back.
        $status = strtoupper(trim((string) (
            data_get($data, 'data.transactionStatus')
            ?? data_get($data, 'transactionStatus')
            ?? data_get($data, 'biz_content.trade_status')
            ?? data_get($data, 'trade_status')
            ?? ''
        )));

        if ($response->successful() && in_array($status, $this->settledStatuses(), true)) {
            // The bank's own references for this transaction. Logged rather
            // than stored because there is nowhere to put them yet; they are
            // what an operator matches against a statement line, so having
            // them in the log is worth more than not having them at all.
            Log::info('Payment verified with the bank', [
                'gateway' => $this->slug(),
                'reference' => $reference,
                'status' => $status,
                'trxn_id' => data_get($data, 'data.trxnID'),
                'ft_number' => data_get($data, 'data.FTNumber'),
                'amount' => data_get($data, 'data.amount.total_amount'),
                'credit_account' => data_get($data, 'data.credit_account'),
                'receipt' => data_get($data, 'data.receipt_link'),
            ]);

            return [
                'success' => true,
                'data' => $data,
                'message' => 'Payment verified successfully',
            ];
        }

        if ($status !== '' && in_array($status, $this->failedStatuses(), true)) {
            // The WHOLE body, not just the status. This is the one branch that
            // writes a contribution off, and the first time it fired in UAT it
            // was impossible to tell why: the summary line said "failed" and
            // the message said "Pending", and nothing had been kept to
            // reconcile the two. A log line that cannot explain the decision it
            // records is not much of a log line.
            Log::warning('Bank reports this payment as failed', [
                'gateway' => $this->slug(),
                'reference' => $reference,
                'status' => $status,
                'http' => $response->status(),
                'body' => $data,
            ]);

            return [
                'success' => false,
                'data' => $data,
                'message' => data_get($data, 'message') ?: 'The charge was not successful.',
            ];
        }

        // The important branch. Unknown status, unexpected HTTP code, an order
        // the bank has not heard of — all of it leaves the contribution where
        // it is.
        //
        // "Transaction not found" is called out separately because it is by far
        // the most common answer here and it means something specific: the
        // member tapped Pay, we created the order, and they never completed it.
        // Dashen never hear of such an order at all.
        //
        // It is still NOT failure on its own. Inside the timeout_express window
        // the customer may simply still be on the PIN screen, and the same
        // "not found" would be returned. Only age settles it, and age is a
        // question about contributions rather than about banking — so this
        // reports the fact and PaymentSettlementService decides what it means.
        $notFound = str_contains(
            strtolower(trim((string) data_get($data, 'message'))),
            'not found'
        );

        Log::warning('Payment verification was inconclusive; leaving pending', [
            'gateway' => $this->slug(),
            'reference' => $reference,
            'http' => $response->status(),
            'status' => $status !== '' ? $status : '(none returned)',
            'not_found' => $notFound,
            'body' => $data,
        ]);

        return [
            'success' => false,
            'pending' => true,
            'not_found' => $notFound,
            'message' => $notFound
                ? 'The bank has no record of this order yet.'
                : 'The bank did not confirm this payment either way.',
            'data' => $data,
        ];
    }

    /**
     * What Dashen tell us about a transaction that settled.
     *
     * Mapped from a real `check-status` response:
     *
     *     data.trxnID              -> transaction_id  MAPS3499011974423323
     *     data.FTNumber            -> bank_reference  444MAPS23213012Y
     *     data.paymentTime         -> paid_at         2026-09-16T11:26:57.328Z
     *     data.amount.total_amount -> amount          1889
     *     data.phoneNumber         -> payer_phone     +251900751969
     *     data.debit_account       -> payer_account   5444484413011
     *     data.receipt_link        -> receipt_url     a page the member can open
     *
     * `paid_at` is the one to be careful about. It is when the money moved,
     * which is NOT payment_date — that is the round the contribution belongs
     * to, routinely weeks earlier. Showing one where the other belongs is what
     * made the admin table read "Sep 4" for a payment made on the 16th.
     *
     * The payer's NAME is not in this response, only their phone and account,
     * even though the SuperApp receipt displays it. Left unknown rather than
     * filled in from the membership: the entire value of these columns is that
     * they say what the BANK says, and a name copied from our own records
     * would quietly destroy that.
     *
     * A malformed date is caught rather than thrown. Failing to parse a
     * timestamp is not a reason to abandon crediting a payment the bank has
     * already confirmed — the money moved either way, and the raw value
     * survives in the payload.
     */
    public function extractSettlement(array $data): array
    {
        $body = (array) data_get($data, 'data', []);

        $paidAt = trim((string) (data_get($body, 'paymentTime') ?: data_get($body, 'trxn_date')));

        try {
            $paidAt = $paidAt !== '' ? Carbon::parse($paidAt) : null;
        } catch (\Throwable $e) {
            Log::warning('Could not read the settlement time from the bank response', [
                'gateway' => $this->slug(),
                'value' => $paidAt,
            ]);

            $paidAt = null;
        }

        return [
            'transaction_id' => data_get($body, 'trxnID'),
            'bank_reference' => data_get($body, 'FTNumber') ?: data_get($body, 'endToEndId'),
            'paid_at' => $paidAt,
            'amount' => data_get($body, 'amount.total_amount'),
            'payer_phone' => data_get($body, 'phoneNumber'),
            'payer_account' => data_get($body, 'debit_account'),
            'receipt_url' => data_get($body, 'receipt_link'),
            // The whole response, credit_account and internalCode included.
            // Fields nobody thought to model are the ones a reconciliation
            // dispute turns on a year later.
            'payload' => $data === [] ? null : $data,
        ];
    }

    /**
     * The only status that means money actually moved.
     *
     * NARROWER THAN THE PARENT'S LIST, DELIBERATELY.
     *
     * FabricGateway accepts SUCCESS, SUCCEEDED, PAID, COMPLETED and
     * TRADE_SUCCESS, because those banks are not consistent with one another
     * and it had to guess. Dashen are consistent: `check-status` answers PAID,
     * observed against live transactions in UAT.
     *
     * Accepting spellings they have never sent buys nothing and risks
     * something. Guessing wrong in THIS direction credits a member for money
     * that has not arrived — an Equb paying out against a contribution nobody
     * made. Guessing wrong in the other direction leaves a contribution pending
     * until an operator looks at it. Those are not comparable mistakes, so this
     * list holds exactly what has been observed and nothing that was imagined.
     *
     * Widen it with DASHEN_SETTLED_STATUSES if they confirm another value.
     *
     * @return array<int, string>
     */
    protected function settledStatuses(): array
    {
        $configured = trim((string) $this->setting('SETTLED_STATUSES'));

        if ($configured !== '') {
            return array_values(array_filter(array_map(
                fn ($value) => strtoupper(trim($value)),
                explode(',', $configured)
            )));
        }

        return ['PAID'];
    }

    /**
     * Statuses that mean the charge is definitively dead.
     *
     * Deliberately a short, explicit list rather than "anything that is not
     * success". Ask Dashen for the full set and add to it; until then an
     * unlisted value leaves the contribution pending, which is the safe
     * direction. Override with DASHEN_FAILED_STATUSES, comma separated.
     *
     * @return array<int, string>
     */
    protected function failedStatuses(): array
    {
        $configured = trim((string) $this->setting('FAILED_STATUSES'));

        if ($configured !== '') {
            return array_values(array_filter(array_map(
                fn ($value) => strtoupper(trim($value)),
                explode(',', $configured)
            )));
        }

        return ['FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED', 'REJECTED', 'DECLINED', 'REVERSED'];
    }
}

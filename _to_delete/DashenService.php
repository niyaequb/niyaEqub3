<?php

namespace App\Services;

use App\Models\EqubPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dashen Bank SuperApp — mini-app payments.
 *
 * This replaced ChapaService. The two are not the same shape and it is worth
 * being clear about why, because the difference decides what the API can
 * promise the client.
 *
 * Chapa was a HOSTED CHECKOUT. We POSTed a transaction, Chapa handed back a
 * URL, and the member was sent to Chapa's page in a browser. The server owned
 * the whole handshake and the client only ever received a link.
 *
 * Dashen is an IN-APP AUTHORISATION. The mini-app runs inside the Dashen
 * SuperApp and talks to it over a JavaScript bridge
 * (`window.dashenbanksuperapp.send`). The SuperApp — not us, and not a web
 * page we control — collects the customer's authorisation. What the server
 * produces is therefore not a URL but a SIGNED ORDER PAYLOAD: the order
 * details, an RSA-encrypted `sign`, and an HMAC-SHA256 `confirmpayload` over
 * the whole request. The mini-app hands that object straight to the SuperApp
 * via `initiatePayment`.
 *
 * WHAT THAT MEANS FOR TRUST
 *
 * The signing material never leaves this class. The mini-app receives a payload
 * it cannot alter — change one field and the HMAC no longer matches what was
 * signed, and Dashen rejects the order ("the payment order won't be processed
 * if there's a mismatch on content signed using hash or encrypted content
 * compared to the other request payload"). That is what lets the amount be
 * server-derived, exactly as it was on the batch path before.
 *
 * WHAT IS STILL OPEN
 *
 * The integration pack Dashen supplied documents the bridge calls and the order
 * payload, and nothing else. It does not document (a) the endpoint that turns a
 * `customeridentifier` into a fabric token, or (b) how Dashen notifies a
 * merchant server that a charge settled. Both are reached here through config
 * (`DASHEN_BASE_URL`, `DASHEN_TOKEN_PATH`, `DASHEN_ORDER_QUERY_PATH`) so that
 * filling them in is an .env change rather than a code change. Until they are
 * filled in, `verifyPayment()` fails closed — see the comment there, and the
 * note in DOCS.md.
 */
class DashenService
{
    protected EnvService $envService;

    public function __construct(EnvService $envService)
    {
        $this->envService = $envService;
    }

    // ---------------------------------------------------------------------
    // Credentials
    // ---------------------------------------------------------------------

    protected function config(string $key, ?string $default = null): ?string
    {
        return $this->envService->get($key, $default);
    }

    /**
     * The mini-app secret. Signs `confirmpayload` and is presented to the
     * SuperApp as `xAPiKey`.
     */
    protected function appSecret(): string
    {
        $secret = $this->config('DASHEN_APP_SECRET');

        if (! $secret) {
            throw new \Exception('Dashen app secret not configured. Please configure it in Settings.');
        }

        return $secret;
    }

    /**
     * The RSA public key issued at onboarding, used to encrypt `sign`.
     *
     * Accepts the key either as a full PEM block or as a bare base64 body —
     * the credential sheet prints it wrapped across lines, and it is easy to
     * paste into .env with the header intact or stripped. Normalising here
     * means neither form is wrong.
     */
    protected function publicKey(): string
    {
        $key = trim((string) $this->config('DASHEN_PUBLIC_KEY'));

        if ($key === '') {
            throw new \Exception('Dashen public key not configured. Please configure it in Settings.');
        }

        // .env cannot hold real newlines, so the key is normally stored with
        // literal \n sequences. Turn those back into line breaks first.
        $key = str_replace('\\n', "\n", $key);

        if (! str_contains($key, 'BEGIN PUBLIC KEY')) {
            $body = preg_replace('/\s+/', '', $key);
            $key = "-----BEGIN PUBLIC KEY-----\n"
                .chunk_split((string) $body, 64, "\n")
                .'-----END PUBLIC KEY-----';
        }

        return $key;
    }

    /** UAT or production. Signed into the request, so it must match the mini-app. */
    public function stage(): string
    {
        return $this->config('DASHEN_STAGE', 'uat') ?: 'uat';
    }

    public function miniAppCode(): string
    {
        return (string) $this->config('DASHEN_MINI_APP_CODE');
    }

    public function merchantCode(): string
    {
        return (string) $this->config('DASHEN_MERCHANT_CODE');
    }

    // ---------------------------------------------------------------------
    // Signing
    // ---------------------------------------------------------------------

    /**
     * RSA-encrypt the signed subset of the request.
     *
     * WHY A SUBSET, AND WHY IT IS CONFIGURABLE
     *
     * Dashen's sample calls `pickKeys(body, keysToPick)` before encrypting and
     * never says what `keysToPick` contains. It cannot be the whole request:
     * RSA encrypts at most (modulus - padding) bytes in one operation, which
     * for the 2048-bit key on the credential sheet is 214 bytes under OAEP.
     * `biz_content` alone is larger than that. So a subset is not an
     * optimisation, it is forced by the maths, and the exact subset is
     * something only Dashen can confirm.
     *
     * DASHEN_SIGN_KEYS therefore holds it, dot-notation, comma-separated. The
     * default below is the smallest set that identifies the transaction —
     * order id, amount, merchant, and the nonce and timestamp that stop it
     * being replayed. If Dashen comes back with a different list, it is an
     * .env change.
     *
     * Oversized input throws rather than being silently truncated or chunked.
     * Chunked RSA would produce ciphertext Dashen's single-shot decrypt cannot
     * read, and the failure would surface as an unexplained rejected payment
     * rather than as the configuration mistake it actually is.
     */
    protected function encryptPayload(array $request): string
    {
        $keys = array_filter(array_map(
            'trim',
            explode(',', (string) $this->config(
                'DASHEN_SIGN_KEYS',
                'biz_content.merch_order_id,biz_content.total_amount,biz_content.merch_code,timestamp,nonce_str'
            ))
        ));

        $picked = [];
        foreach ($keys as $key) {
            $value = data_get($request, $key);
            if ($value !== null) {
                data_set($picked, $key, $value);
            }
        }

        $plain = json_encode($picked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // OAEP by default, matching Node's crypto.publicEncrypt, which is what
        // the sample uses. Some fabric deployments are on PKCS#1 v1.5 instead;
        // DASHEN_RSA_PADDING=pkcs1 switches it without touching this file.
        $padding = strtolower((string) $this->config('DASHEN_RSA_PADDING', 'oaep')) === 'pkcs1'
            ? OPENSSL_PKCS1_PADDING
            : OPENSSL_PKCS1_OAEP_PADDING;

        $encrypted = '';
        $ok = openssl_public_encrypt($plain, $encrypted, $this->publicKey(), $padding);

        if (! $ok) {
            $reason = openssl_error_string() ?: 'unknown OpenSSL error';

            Log::error('Dashen payload encryption failed', [
                'reason' => $reason,
                'signed_bytes' => strlen((string) $plain),
                'signed_keys' => $keys,
            ]);

            throw new \Exception(
                'Could not sign the Dashen payment order ('.$reason.'). '
                .'If this says "data too large for key size", narrow DASHEN_SIGN_KEYS.'
            );
        }

        return base64_encode($encrypted);
    }

    /**
     * HMAC-SHA256 over the whole request, including `stage`.
     *
     * KEY ORDERING IS LOAD-BEARING. Dashen hashes the JSON text, not a
     * canonicalised object, so the bytes we hash must be the bytes we send.
     * PHP preserves array insertion order through json_encode, and every
     * caller here builds the request in the same order the sample does
     * (timestamp, nonce_str, method, version, biz_content, sign, stage). Do not
     * reorder or re-sort these arrays.
     *
     * JSON_UNESCAPED_SLASHES matters for the same reason: PHP escapes "/" as
     * "\/" by default and Node does not, so without it an amount or narration
     * containing a slash would hash differently on the two sides.
     */
    protected function createHmac(array $request): string
    {
        return hash_hmac(
            'sha256',
            (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->appSecret()
        );
    }

    /**
     * Narration text, kept to a conservative character set.
     *
     * Dashen does not publish a restriction on `biz_content.title`. This is
     * precautionary rather than documented: the field is a bank narration, it
     * carries names members typed in themselves (including Amharic, which will
     * not survive an ASCII-only rail), and Chapa rejected whole transactions
     * over exactly this. Losing a cosmetic label is always better than losing
     * the payment, so anything unusable is replaced by the caller's fallback.
     */
    protected function narration(?string $value, string $fallback, int $limit = 50): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_. ]+/', ' ', (string) $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean), ' .');

        if ($clean === '') {
            return $fallback;
        }

        return rtrim(mb_substr($clean, 0, $limit), ' .');
    }

    // ---------------------------------------------------------------------
    // Order construction
    // ---------------------------------------------------------------------

    /**
     * Assemble and sign one payment order.
     *
     * The returned array is what the mini-app passes to the SuperApp as
     * `orderPayload`. It is complete and tamper-evident; the client adds only
     * its auth header pair and the callback name.
     */
    protected function buildOrderPayload(string $merchOrderId, float $amount, string $title): array
    {
        $request = [
            'timestamp' => (string) now()->timestamp,
            'nonce_str' => strtoupper(Str::random(32)),
            'method' => 'payment.preorder',
            'version' => '1.0',
        ];

        $request['biz_content'] = [
            'trade_type' => 'InApp',
            'appid' => $this->miniAppCode(),
            'merch_code' => $this->merchantCode(),
            'merch_order_id' => $merchOrderId,
            'title' => $title,
            // Two decimal places as a string. The column is decimal(12,2) and
            // the money must not go through a binary float on the way out.
            'total_amount' => number_format($amount, 2, '.', ''),
            'trans_currency' => 'ETB',
            'timeout_express' => $this->config('DASHEN_TIMEOUT_EXPRESS', '120m'),
            'payee_identifier' => $this->merchantCode(),
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
        ];

        // Order matters from here down — see createHmac().
        $request['sign'] = $this->encryptPayload($request);
        $request['stage'] = $this->stage();
        $request['confirmpayload'] = $this->createHmac($request);

        return $request;
    }

    /**
     * The `authPayload` half of an initiatePayment call.
     *
     * RAISE THIS WITH DASHEN BEFORE GOING TO PRODUCTION.
     *
     * Their sample passes `xAPiKey: {{app_secret_here}}` from inside mini-app
     * JavaScript, which means the app secret — the same secret that signs
     * `confirmpayload` — has to be readable in the browser. Anyone who opens
     * devtools can then mint orders that carry a valid signature, which
     * defeats the point of signing them on the server at all.
     *
     * It is implemented here because it is what the integration pack specifies
     * and payments do not work without it, not because it is sound. The right
     * fix is a short-lived, order-scoped token from Dashen instead of the
     * long-lived secret; ask them whether one exists. Until then, treat
     * DASHEN_APP_SECRET as public and rely on the server-side amount checks in
     * EqubPaymentController — which is why those checks read the amount from
     * the membership rather than trusting the request.
     */
    public function authPayload(?string $fabricToken = null): array
    {
        return [
            'xAPiKey' => $this->appSecret(),
            'xAccessToken' => $fabricToken,
        ];
    }

    /**
     * One contribution, one order.
     */
    public function createEqubOrder(EqubPayment $payment): array
    {
        $membership = $payment->membership;

        // Whoever is actually paying. On a place held for someone with no Niya
        // account ("My Responsibility People") that is the sponsor — the place
        // itself has nobody behind it.
        $isSeat = (bool) $membership?->isResponsibilitySeat();

        $title = $isSeat
            ? $this->narration($membership->displayName(), 'Equb contribution payment', 30)
            : 'Equb contribution payment';

        try {
            return [
                'success' => true,
                'order_payload' => $this->buildOrderPayload(
                    $payment->reference,
                    (float) $payment->amount,
                    $title,
                ),
                // The fabric token is the mini-app's own, held from sign-in;
                // the server never sees it, so it is left null here and the
                // client fills it in.
                'auth_payload' => $this->authPayload(),
                'reference' => $payment->reference,
            ];
        } catch (\Throwable $e) {
            Log::error('Dashen Equb order creation failed', [
                'equb_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment could not be started. Please try again.',
            ];
        }
    }

    /**
     * Several contributions, one order.
     *
     * A member settling their own place and the places they hold for other
     * people is charged once, for the total. Each place keeps its own
     * equb_payments row; they share $batchReference, which is the
     * merch_order_id Dashen carries and what settlement resolves them by.
     *
     * @param  array<int, EqubPayment>  $payments
     */
    public function createEqubBatchOrder(
        array $payments,
        string $batchReference,
        float $total,
    ): array {
        $count = count($payments);

        $title = $count > 1
            ? $this->narration("Equb contribution for {$count} places", 'Equb contribution payment')
            : 'Equb contribution payment';

        try {
            return [
                'success' => true,
                'order_payload' => $this->buildOrderPayload($batchReference, $total, $title),
                'auth_payload' => $this->authPayload(),
                'reference' => $batchReference,
            ];
        } catch (\Throwable $e) {
            Log::error('Dashen Equb batch order creation failed', [
                'batch_reference' => $batchReference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment could not be started. Please try again.',
            ];
        }
    }

    // ---------------------------------------------------------------------
    // Settlement
    // ---------------------------------------------------------------------

    /**
     * Ask Dashen whether a transaction actually completed.
     *
     * NOT YET WIRED, AND FAILING CLOSED ON PURPOSE.
     *
     * Dashen has not supplied the order-query endpoint, so DASHEN_BASE_URL and
     * DASHEN_ORDER_QUERY_PATH are empty out of the box and this returns
     * failure. That is the safe direction: a contribution stays `pending`, the
     * member is not credited, and reconciliation shows an obvious gap. The
     * alternative — treating an unverifiable notification as proof — would
     * credit members for money nobody confirmed arrived, and it is exactly the
     * mistake the Chapa integration went out of its way to avoid.
     *
     * Fill both values in and this becomes live with no code change.
     */
    public function verifyPayment(string $reference): array
    {
        $baseUrl = trim((string) $this->config('DASHEN_BASE_URL'));
        $queryPath = trim((string) $this->config('DASHEN_ORDER_QUERY_PATH'));

        if ($baseUrl === '' || $queryPath === '') {
            Log::warning('Dashen verification skipped: order query endpoint is not configured', [
                'reference' => $reference,
            ]);

            return [
                'success' => false,
                'message' => 'Dashen order verification endpoint is not configured.',
                'unconfigured' => true,
            ];
        }

        try {
            $request = [
                'timestamp' => (string) now()->timestamp,
                'nonce_str' => strtoupper(Str::random(32)),
                'method' => 'payment.queryorder',
                'version' => '1.0',
            ];

            $request['biz_content'] = [
                'merch_code' => $this->merchantCode(),
                'merch_order_id' => $reference,
            ];

            $request['sign'] = $this->encryptPayload($request);
            $request['stage'] = $this->stage();
            $request['confirmpayload'] = $this->createHmac($request);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->appSecret(),
            ])->post(rtrim($baseUrl, '/').'/'.ltrim($queryPath, '/'), $request);

            $data = $response->json();

            // Accept the shapes a fabric gateway commonly returns rather than
            // insisting on one. What is NOT accepted is an HTTP 200 on its own
            // — the body has to say the trade succeeded.
            $status = strtoupper((string) (
                data_get($data, 'biz_content.trade_status')
                ?? data_get($data, 'trade_status')
                ?? data_get($data, 'status')
                ?? ''
            ));

            $settled = in_array($status, ['SUCCESS', 'SUCCEEDED', 'PAID', 'COMPLETED', 'TRADE_SUCCESS'], true);

            if ($response->successful() && $settled) {
                return [
                    'success' => true,
                    'data' => $data,
                    'message' => 'Payment verified successfully',
                ];
            }

            Log::info('Dashen verification did not confirm settlement', [
                'reference' => $reference,
                'status' => $status,
                'body' => $data,
            ]);

            return [
                'success' => false,
                'message' => data_get($data, 'message') ?? 'Payment verification failed',
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Dashen payment verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify the HMAC on an inbound settlement notification.
     *
     * Constant-time comparison, over the RAW body — re-encoding the parsed
     * array would change whitespace and key order and break a signature that
     * was perfectly valid.
     */
    public function verifyNotificationSignature(string $rawBody, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $rawBody, $this->appSecret()),
            $signature
        );
    }

    /**
     * Settle the contributions behind one Dashen transaction.
     *
     * Resolution is two-stage and in this order: an exact match on `reference`
     * first, and only if nothing is found, on `batch_reference`. That keeps the
     * single-payment path unchanged while supporting batches, and it is the
     * same rule the platform's own callback documentation describes.
     */
    public function handleNotificationForEqubPayment(
        array $payload,
        ?string $signature = null,
        ?string $rawBody = null,
    ): array {
        // An unsigned notification is not a notification. Unlike the Chapa
        // implementation this replaced, the check is not skipped when no
        // secret happens to be set — an unauthenticated public route that
        // marks money as received is not something to leave conditional.
        if (! $this->verifyNotificationSignature($rawBody ?? (string) json_encode($payload), $signature)) {
            Log::warning('Dashen notification rejected: signature did not verify', [
                'reference' => $payload['merch_order_id'] ?? null,
            ]);

            return ['success' => false, 'message' => 'Invalid notification signature'];
        }

        $reference = $payload['merch_order_id']
            ?? data_get($payload, 'biz_content.merch_order_id')
            ?? $payload['tx_ref']
            ?? null;

        if (! $reference) {
            Log::error('Dashen notification: reference not found', ['payload' => $payload]);

            return ['success' => false, 'message' => 'Reference not found in notification'];
        }

        $payments = EqubPayment::where('reference', $reference)->get();

        if ($payments->isEmpty()) {
            $payments = EqubPayment::where('batch_reference', $reference)->get();
        }

        if ($payments->isEmpty()) {
            Log::error('Dashen notification: Equb payment not found', ['reference' => $reference]);

            return ['success' => false, 'message' => 'Equb payment not found'];
        }

        Log::info('Dashen Equb notification payload: '.json_encode($payload));

        // Independent verification, always. What the notification asserts is
        // a claim; what the query endpoint says is the fact. A notification
        // claiming success for a transaction Dashen will not confirm marks the
        // contributions failed rather than paid.
        $verification = $this->verifyPayment($reference);

        if (! $verification['success']) {
            // An unconfigured verifier is an operational gap, not a failed
            // charge. Leaving the rows pending keeps the money visible as
            // unreconciled instead of writing off a payment that may well have
            // succeeded.
            if ($verification['unconfigured'] ?? false) {
                Log::warning('Dashen notification left pending: cannot verify', [
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

            Log::warning('Dashen Equb notification verification failed', [
                'reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
            ]);

            return ['success' => false, 'message' => $verification['message']];
        }

        // Every contribution in the charge settles together. Marking only one
        // would leave the member's own place paid and the places they pay for
        // still showing as owed, after they had already been charged for all
        // of them.
        foreach ($payments as $payment) {
            if (! $payment->isPending()) {
                continue;
            }

            $payment->markAsPaid();

            if ($membership = $payment->membership) {
                app(EqubMembershipService::class)->completeIfEligible($membership);
            }
        }

        $this->announceEqubPaymentSettled($payments, $reference);

        Log::info('Dashen Equb notification processed successfully', [
            'reference' => $reference,
            'payments' => $payments->pluck('id')->all(),
        ]);

        return [
            'success' => true,
            'payment' => $payments->first(),
            'payments' => $payments,
            'message' => 'Equb payment verified and processed',
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
    protected function announceEqubPaymentSettled($payments, string $reference): void
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

    // ---------------------------------------------------------------------
    // Login with Dashen
    // ---------------------------------------------------------------------

    /**
     * Exchange the SuperApp's `customeridentifier` for a fabric token and the
     * customer's identity.
     *
     * This is the server half of "Login with DBSA". The mini-app asks the
     * SuperApp for an identifier over the bridge and POSTs it here; we exchange
     * it with Dashen and get back something that names the customer — in
     * practice a phone number, which is what maps onto a Niya member.
     *
     * NOT YET WIRED. Dashen has not supplied this endpoint either, so it is
     * reached through DASHEN_BASE_URL + DASHEN_TOKEN_PATH and returns failure
     * until both are set. The mini-app falls back to ordinary phone-and-OTP
     * sign-in in the meantime, so the product still works — members just sign
     * in themselves rather than being recognised by the SuperApp.
     */
    public function exchangeCustomerIdentifier(string $customerIdentifier): array
    {
        $baseUrl = trim((string) $this->config('DASHEN_BASE_URL'));
        $tokenPath = trim((string) $this->config('DASHEN_TOKEN_PATH'));

        if ($baseUrl === '' || $tokenPath === '') {
            return [
                'success' => false,
                'message' => 'Dashen token endpoint is not configured.',
                'unconfigured' => true,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->appSecret(),
            ])->post(rtrim($baseUrl, '/').'/'.ltrim($tokenPath, '/'), [
                'appid' => $this->miniAppCode(),
                'fabric_app_id' => $this->config('DASHEN_FABRIC_APP_ID'),
                'merch_code' => $this->merchantCode(),
                'stage' => $this->stage(),
                'customeridentifier' => $customerIdentifier,
            ]);

            $data = $response->json();

            if (! $response->successful()) {
                Log::warning('Dashen identifier exchange failed', ['body' => $data]);

                return [
                    'success' => false,
                    'message' => data_get($data, 'message') ?? 'Could not verify the SuperApp session.',
                ];
            }

            return [
                'success' => true,
                'token' => data_get($data, 'token') ?? data_get($data, 'access_token'),
                'phone' => data_get($data, 'phone')
                    ?? data_get($data, 'mobile')
                    ?? data_get($data, 'customer.phone'),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('Dashen identifier exchange errored', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

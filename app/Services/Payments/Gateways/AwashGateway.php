<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\HandlesCustomerReturn;
use App\Contracts\PaymentGateway;
use App\Models\EqubPayment;
use App\Models\PaymentGatewayOrder;
use App\Services\EnvService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Awash Bank payment gateway: Merchant Customer Debit ("Jupiter").
 *
 * THE FLOW (same shape as Hijra: the member never leaves the Niya app)
 *
 *     POST MerchantRS/DebitRequest   we send phone + amount + externalReference
 *     Awash                          asks the customer on their phone to approve
 *     member                         approves the debit with their PIN
 *     Awash (Jupiter)                POSTs the result to our callbackUrl
 *                                    (/payment/awash/callback)
 *     POST MerchantRS/DebitStatus    we ASK Awash the status, and only then credit
 *
 * The callback is never trusted on its own: Awash's collection defines no
 * signature on it. It only makes us ask DebitStatus early, through the same
 * PaymentSettlementService::reconcile() the five-minute sweep uses.
 *
 * AUTHENTICATION
 *
 * Every request carries merchantCode, merchantTillNumber, a fresh requestId
 * and requestSignature = sha256hex(password + requestId). No token.
 *
 * OUR REFERENCE vs AWASH'S externalReference
 *
 * Awash's samples only ever use digits for externalReference (100000000009,
 * 92038542). Our references are EQUB-XXXXXXXXXXXX. Rather than find out in
 * production whether letters are accepted, every order is sent with its own
 * 12-digit externalReference, kept in payment_gateway_orders.external_order_id
 * next to our reference. The callback and DebitStatus are matched through it.
 *
 * NO DOUBLE DEBIT
 *
 * If an order is started again for a reference that already has an Awash
 * request (the member pressed Pay twice, an admin re-initiated it), Awash is
 * asked about the earlier one first:
 *     APPROVED          no new request; settlement will credit the earlier one
 *     still waiting     no new request; the member is told to approve the first
 *     final / failed    a new request is sent with a new externalReference
 *
 * FAILING CLOSED, AS EVERY GATEWAY HERE MUST
 *
 *     APPROVED                       paid, so credit it (after checks)
 *     REJECTED, DECLINED, FAILED,
 *     CANCELLED, EXPIRED, TIMEOUT    final, not paid: mark failed
 *     anything else, or no answer    leave pending
 *
 * Before crediting: the externalReference Awash reports must be ours and the
 * amount must be what we sent.
 *
 * SETTINGS (settings table first, .env second, through EnvService)
 *
 *     AWASH_BASE_URL            https://awashpay.awashbank.com/awashpaytest/Rest
 *     AWASH_MERCHANT_CODE       issued by Awash
 *     AWASH_TILL_NUMBER         issued by Awash (merchantTillNumber)
 *     AWASH_PASSWORD            issued by Awash, used only to sign requests
 *     AWASH_RESOLVE_IP          optional: connect to this IP for the BASE_URL host
 *                               (10.10.88.136 through the VPN), keeping the
 *                               certificate check on the real host name
 *     AWASH_SOURCE_IP           optional: send from this local IP (172.27.250.10)
 *     AWASH_CALLBACK_URL        optional: default {APP_URL}/payment/awash/callback
 *     AWASH_SHOW_IN_APPS        false hides it from the apps (awash:test still works)
 *     AWASH_TIMEOUT_SECONDS     HTTP timeout (default 30)
 *     AWASH_INSECURE_SKIP_TLS_VERIFY  UAT only, never in production
 */
class AwashGateway implements HandlesCustomerReturn, PaymentGateway
{
    protected const SETTLED = ['APPROVED'];

    protected const FINAL_FAILED = [
        'REJECTED', 'DECLINED', 'FAILED', 'CANCELLED', 'CANCELED',
        'EXPIRED', 'TIMEOUT', 'TIMED_OUT', 'REVERSED',
    ];

    protected EnvService $env;

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(EnvService $env, array $config = [])
    {
        $this->env = $env;
        $this->config = $config;
    }

    // ---------------------------------------------------------------------
    // Identity and settings
    // ---------------------------------------------------------------------

    public function slug(): string
    {
        return 'awash';
    }

    public function displayName(): string
    {
        return $this->config['name'] ?? 'Awash Bank';
    }

    protected function envPrefix(): string
    {
        return $this->config['env_prefix'] ?? 'AWASH';
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->env->get($this->envPrefix().'_'.$key, $default);

        return $value === null ? $default : trim((string) $value);
    }

    public function isConfigured(): bool
    {
        foreach (['BASE_URL', 'MERCHANT_CODE', 'TILL_NUMBER', 'PASSWORD'] as $key) {
            if ((string) $this->setting($key) === '') {
                return false;
            }
        }

        return true;
    }

    /** DebitStatus needs nothing beyond what DebitRequest needs. */
    public function canVerifySettlement(): bool
    {
        return $this->isConfigured();
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->setting('BASE_URL'), '/').'/'.ltrim($path, '/');
    }

    public function callbackUrl(): string
    {
        $configured = (string) $this->setting('CALLBACK_URL');

        return $configured !== '' ? $configured : url('/payment/'.$this->slug().'/callback');
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) $this->setting('TIMEOUT_SECONDS', '30') ?: 30)
            ->connectTimeout(10);

        $curl = [];

        // Reach the public host name through the tunnel: connect to the private
        // IP but keep SNI and the certificate check on the real name.
        $resolveIp = (string) $this->setting('RESOLVE_IP');
        if ($resolveIp !== '') {
            $parts = parse_url((string) $this->setting('BASE_URL'));
            $host = (string) ($parts['host'] ?? '');
            $port = (int) ($parts['port'] ?? ((($parts['scheme'] ?? 'https') === 'http') ? 80 : 443));

            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$resolveIp}"];
            }
        }

        // Leave from the address the tunnel is built for.
        $sourceIp = (string) $this->setting('SOURCE_IP');
        if ($sourceIp !== '') {
            $curl[CURLOPT_INTERFACE] = $sourceIp;
        }

        if ($curl !== []) {
            $request = $request->withOptions(['curl' => $curl]);
        }

        // Only for a UAT host whose certificate this server cannot validate.
        // Never set it in production.
        if (filter_var($this->setting('INSECURE_SKIP_TLS_VERIFY', 'false'), FILTER_VALIDATE_BOOL)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    // ---------------------------------------------------------------------
    // Authorization block: sha256hex(password + requestId)
    // ---------------------------------------------------------------------

    /** A fresh, numeric, 16-digit request id (Awash's sample: 0000069000000627). */
    public function newRequestId(): string
    {
        return now()->format('ymdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function signature(string $requestId): string
    {
        return hash('sha256', (string) $this->setting('PASSWORD').$requestId);
    }

    /** @return array<string, string> */
    public function authorization(?string $requestId = null): array
    {
        $requestId ??= $this->newRequestId();

        return [
            'merchantCode' => (string) $this->setting('MERCHANT_CODE'),
            'merchantTillNumber' => (string) $this->setting('TILL_NUMBER'),
            'requestId' => $requestId,
            'requestSignature' => $this->signature($requestId),
        ];
    }

    /**
     * One call to Awash. The password never leaves this method's caller; the
     * log only ever sees the response.
     *
     * @param  array<string, mixed>  $paymentRequest
     */
    protected function call(string $path, array $paymentRequest): \Illuminate\Http\Client\Response
    {
        return $this->http()->post($this->url($path), [
            'authorization' => $this->authorization(),
            'paymentRequest' => $paymentRequest,
        ]);
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /**
     * Ask Awash to send a debit approval request to the payer's phone.
     *
     * Throws on any refusal. EqubOrderService logs it and shows the member a
     * plain message; nothing was charged.
     */
    public function createOrder(string $reference, float $amount, string $narration): array
    {
        $amount = round($amount, 2);

        if ($amount < 0.01) {
            throw new \RuntimeException('Awash order amount must be at least 0.01.');
        }

        $phone = $this->payerPhone($reference);

        if ($phone === null) {
            throw new \RuntimeException('No valid phone number on the paying member for '.$reference.'.');
        }

        return $this->sendDebitRequest($reference, $amount, $phone, $narration);
    }

    /**
     * The DebitRequest itself. Public so awash:test can send one for a test
     * reference that has no contribution behind it.
     */
    public function sendDebitRequest(string $reference, float $amount, string $phone, string $narration): array
    {
        $amount = round($amount, 2);
        $minor = (int) round($amount * 100);

        $existing = PaymentGatewayOrder::query()
            ->where('gateway', $this->slug())
            ->where('reference', $reference)
            ->first();

        if ($existing && $existing->external_order_id) {
            $earlier = $this->guardAgainstSecondDebit($existing, $minor, $phone);

            if ($earlier !== null) {
                return $earlier;
            }
        }

        $externalReference = $this->newExternalReference();

        $paymentRequest = [
            'payerPhone' => $phone,
            'reason' => $this->narration($narration),
            'amount' => number_format($amount, 2, '.', ''),
            'externalReference' => $externalReference,
            'callbackUrl' => $this->callbackUrl(),
        ];

        try {
            $response = $this->call('MerchantRS/DebitRequest', $paymentRequest);
        } catch (\Throwable $e) {
            Log::error('Awash DebitRequest could not be sent', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not reach Awash Bank to start this payment.');
        }

        $data = (array) ($response->json() ?? []);
        $returnCode = $data['returnCode'] ?? null;

        if (! $response->successful() || ! is_numeric($returnCode) || (int) $returnCode !== 0) {
            Log::error('Awash refused the DebitRequest', [
                'reference' => $reference,
                'external_reference' => $externalReference,
                'http' => $response->status(),
                'body' => $data ?: mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException(
                'Awash Bank did not accept the payment ('
                .($data['returnMessage'] ?? 'HTTP '.$response->status()).').'
            );
        }

        PaymentGatewayOrder::updateOrCreate(
            ['gateway' => $this->slug(), 'reference' => $reference],
            [
                'external_order_id' => $externalReference,
                'checkout_url' => null,
                'amount_minor' => $minor,
                'currency' => 'ETB',
                'status' => 'requested',
                'last_checked_at' => null,
                'register_response' => $data + [
                    '_payer_phone' => $phone,
                    '_previous' => $existing?->external_order_id ? [
                        'external_reference' => $existing->external_order_id,
                        'status' => $existing->status,
                    ] : null,
                ],
                'status_response' => null,
            ]
        );

        Log::info('Awash debit request sent', [
            'reference' => $reference,
            'external_reference' => $externalReference,
            'awash_request_id' => $data['requestId'] ?? null,
            'phone' => $this->mask($phone),
            'amount' => $amount,
            'expires' => $data['expires'] ?? null,
        ]);

        return $this->waitingPayload($reference, $amount, $phone, $data['instructions'] ?? null);
    }

    /**
     * An Awash request already exists for this reference. Ask about it before
     * sending another, so a member is never asked to approve the same debt twice.
     *
     * @return array<string, mixed>|null  the payload to return instead, or null to send a new request
     */
    protected function guardAgainstSecondDebit(PaymentGatewayOrder $existing, int $minor, string $phone): ?array
    {
        $result = $this->verifyPayment($existing->reference);
        $amount = $existing->amount_minor / 100;

        if ($result['success'] ?? false) {
            throw new \RuntimeException('Awash already approved reference '.$existing->reference.'; it will be credited, do not pay again.');
        }

        // An explicit final "no": a new request with a new externalReference.
        if (isset($result['status']) && ! ($result['pending'] ?? false)) {
            return null;
        }

        // Still waiting, not found yet, or no clear answer. A second request could be a second
        // debit. Re-send only once the first has expired by Awash's own clock.
        $expires = $this->expiresAt($existing);

        if ($expires === null || $expires->isFuture()) {
            Log::info('Awash debit already waiting for approval; not sending a second', [
                'reference' => $existing->reference,
                'external_reference' => $existing->external_order_id,
            ]);

            return $this->waitingPayload($existing->reference, $amount, $phone, null, $expires);
        }

        return null;
    }

    protected function expiresAt(PaymentGatewayOrder $order): ?Carbon
    {
        $raw = $order->register_response['expires'] ?? null;

        if (! filled($raw)) {
            // No expiry given: assume Awash's prompt lasts at most this long.
            return ($order->updated_at ?? $order->created_at)?->copy()->addMinutes(max(2, (int) $this->setting('APPROVAL_MINUTES', '10')));
        }

        try {
            return Carbon::parse((string) $raw, (string) $this->setting('TIMEZONE', 'Africa/Addis_Ababa'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function waitingPayload(string $reference, float $amount, string $phone, ?string $instructions, ?Carbon $expires = null): array
    {
        $seconds = $expires && $expires->isFuture()
            ? (int) max(60, now()->diffInSeconds($expires))
            : max(2, (int) $this->setting('APPROVAL_MINUTES', '10')) * 60;

        $message = trim((string) $instructions) !== ''
            ? trim((string) $instructions)
            : 'Check your phone '.$this->mask($phone).' and approve the Awash Bank request for '
                .number_format($amount, 2).' ETB.';

        return [
            // Same kind as Hijra: the app shows the message, then polls.
            'type' => 'ussd_push',
            'reference' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'ETB',
            'phone' => $this->mask($phone),
            'expires_in_seconds' => $seconds,
            'message' => $message,
        ];
    }

    /** 12 digits, unique among our Awash orders. */
    protected function newExternalReference(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = (string) random_int(100000000000, 999999999999);

            $taken = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('external_order_id', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not pick a free Awash external reference.');
    }

    /**
     * The phone of whoever is paying: the member, or the sponsor of a place
     * held for someone else. The same person the SMS receipt goes to.
     */
    protected function payerPhone(string $reference): ?string
    {
        $payment = EqubPayment::query()
            ->where('reference', $reference)
            ->orWhere('batch_reference', $reference)
            ->with('membership')
            ->first();

        return $this->normalisePhone($payment?->membership?->payerUser()?->phone);
    }

    /** Awash's sample payerPhone is 2519XXXXXXXX. */
    public function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '251')) {
            $digits = substr($digits, 3);
        }

        $digits = ltrim($digits, '0');

        // Ethiopian mobile: 9XXXXXXXX (Ethio telecom) or 7XXXXXXXX (Safaricom).
        if (! preg_match('/^[79]\d{8}$/', $digits)) {
            return null;
        }

        return '251'.$digits;
    }

    protected function mask(string $phone): string
    {
        return strlen($phone) > 6 ? substr($phone, 0, 5).str_repeat('*', strlen($phone) - 7).substr($phone, -2) : $phone;
    }

    protected function narration(?string $value): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9\-_.,: ]+/', ' ', (string) $value)));

        return $clean === '' ? 'Niya Equb contribution' : mb_substr($clean, 0, 50);
    }

    /** The member's phone confirms; no client credential is needed. */
    public function authPayload(?string $customerIdentifier = null): array
    {
        return [];
    }

    // ---------------------------------------------------------------------
    // Settlement
    // ---------------------------------------------------------------------

    /**
     * Ask Awash whether this debit was approved.
     *
     * @return array{success: bool, message?: string, data?: mixed, unconfigured?: bool, pending?: bool, not_found?: bool, answered?: bool, status?: mixed}
     */
    public function verifyPayment(string $reference): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'unconfigured' => true,
                'message' => $this->displayName().' is not configured on this server.',
            ];
        }

        $order = PaymentGatewayOrder::query()
            ->where('gateway', $this->slug())
            ->where('reference', $reference)
            ->first();

        if (! $order || ! $order->external_order_id) {
            // We never sent Awash anything for this reference.
            return [
                'success' => false,
                'pending' => true,
                'not_found' => true,
                'answered' => true,
                'message' => 'No Awash Bank request was sent for this reference.',
            ];
        }

        $externalReference = (string) $order->external_order_id;

        try {
            $response = $this->call('MerchantRS/DebitStatus', ['externalReference' => $externalReference]);
        } catch (\Throwable $e) {
            Log::error('Awash DebitStatus could not reach Awash', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'pending' => true, 'message' => 'Could not reach Awash Bank to verify this payment.'];
        }

        $body = (array) ($response->json() ?? []);
        $data = ['response' => $body, 'external_reference' => $externalReference];
        $returnCode = $body['returnCode'] ?? null;
        $status = strtoupper(trim((string) ($body['status'] ?? '')));

        if (! $response->successful() || ! is_numeric($returnCode) || (int) $returnCode !== 0) {
            $message = (string) ($body['returnMessage'] ?? '');
            $notFound = $message !== '' && preg_match('/not\s*found|no\s*record|does\s*not\s*exist|invalid\s*(external\s*)?reference/i', $message);

            $order->forceFill([
                'status' => $notFound ? 'not_found' : $order->status,
                'last_checked_at' => now(),
                'status_response' => $body ?: ['raw' => mb_substr($response->body(), 0, 500)],
            ])->save();

            Log::warning('Awash DebitStatus was inconclusive; leaving pending', [
                'reference' => $reference,
                'external_reference' => $externalReference,
                'http' => $response->status(),
                'body' => $body ?: mb_substr($response->body(), 0, 500),
            ]);

            return [
                'success' => false,
                'pending' => true,
                'not_found' => (bool) $notFound,
                'answered' => $response->successful(),
                'message' => $notFound
                    ? 'Awash Bank has no payment for this reference.'
                    : 'Awash Bank could not look this payment up right now.',
                'data' => $data,
            ];
        }

        $order->forceFill([
            'status' => $status !== '' ? strtolower($status) : $order->status,
            'last_checked_at' => now(),
            'status_response' => $body,
        ])->save();

        if (in_array($status, self::SETTLED, true)) {
            $reportedRef = (string) ($body['externalReference'] ?? '');
            $reportedAmount = $body['amount'] ?? null;

            $refMismatch = $reportedRef !== '' && $reportedRef !== $externalReference;
            $amountMismatch = ! is_numeric($reportedAmount)
                || (int) round(((float) $reportedAmount) * 100) !== (int) $order->amount_minor;

            if ($refMismatch || $amountMismatch) {
                Log::critical('Awash reports APPROVED but the payment does not match ours; NOT credited', [
                    'reference' => $reference,
                    'external_reference' => $externalReference,
                    'reported_reference' => $reportedRef,
                    'reported_amount' => $reportedAmount,
                    'registered_amount_minor' => $order->amount_minor,
                ]);

                return [
                    'success' => false,
                    'pending' => true,
                    'message' => 'Awash Bank reported this payment with details that do not match the order.',
                    'data' => $data,
                ];
            }

            Log::info('Awash payment verified', [
                'reference' => $reference,
                'external_reference' => $externalReference,
                'transaction_id' => $body['transactionId'] ?? null,
            ]);

            return ['success' => true, 'data' => $data, 'message' => 'Payment verified successfully'];
        }

        if (in_array($status, self::FINAL_FAILED, true)) {
            return [
                'success' => false,
                'status' => $status,
                'message' => match ($status) {
                    'CANCELLED', 'CANCELED' => 'The payment was cancelled on the phone.',
                    'EXPIRED', 'TIMEOUT', 'TIMED_OUT' => 'The request expired before it was approved.',
                    'REJECTED', 'DECLINED' => 'The payment was declined.',
                    default => 'Awash Bank could not complete the payment.',
                },
                'data' => $data,
            ];
        }

        // Waiting for approval, or a status nobody has told us the meaning of.
        // Money may be moving: never fail it from here.
        if (! in_array($status, ['PENDING', 'REQUESTED', 'INITIATED', 'WAITING', 'PROCESSING', 'NEW', ''], true)) {
            Log::warning('Awash returned an unknown status; leaving pending', ['reference' => $reference, 'status' => $status]);
        }

        return [
            'success' => false,
            'pending' => true,
            'answered' => true,
            'status' => $status,
            'message' => 'Waiting for the payment to be approved on the phone.',
            'data' => $data,
        ];
    }

    public function extractSettlement(array $data): array
    {
        $body = (array) ($data['response'] ?? []);

        try {
            // Awash send local time without an offset: 2024-05-25 22:04:46.
            $paidAt = filled($body['dateApproved'] ?? null)
                ? Carbon::parse((string) $body['dateApproved'], (string) $this->setting('TIMEZONE', 'Africa/Addis_Ababa'))
                : null;
        } catch (\Throwable) {
            $paidAt = null;
        }

        return [
            'transaction_id' => isset($body['transactionId']) ? (string) $body['transactionId'] : null,
            'bank_reference' => isset($body['externalReference']) ? (string) $body['externalReference'] : ($data['external_reference'] ?? null),
            'paid_at' => $paidAt,
            'amount' => is_numeric($body['amount'] ?? null) ? number_format((float) $body['amount'], 2, '.', '') : null,
            'payer_phone' => isset($body['payerPhone']) ? (string) $body['payerPhone'] : null,
            'payload' => $data === [] ? null : $data,
        ];
    }

    // ---------------------------------------------------------------------
    // Inbound (callback)
    // ---------------------------------------------------------------------

    /**
     * Awash's collection defines no callback signature. If they provide a
     * shared secret, set AWASH_CALLBACK_SECRET and it is checked here.
     * Either way nothing is settled on the callback itself.
     */
    public function verifyNotificationSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) $this->setting('CALLBACK_SECRET');

        if ($secret === '' || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower(trim($signature)));
    }

    public function signatureHeaders(): array
    {
        return $this->config['signature_headers'] ?? ['x-awash-signature'];
    }

    public function extractReference(array $payload): ?string
    {
        return $this->referenceFromReturn($payload);
    }

    /**
     * Which of our references a callback is about. Accepted only if we sent
     * that externalReference to Awash, so a forged call can at most make us
     * ask Awash a question.
     */
    public function referenceFromReturn(array $params): ?string
    {
        $candidates = [
            $params['externalReference'] ?? null,
            data_get($params, 'paymentRequest.externalReference'),
            data_get($params, 'data.externalReference'),
        ];

        foreach ($candidates as $external) {
            $external = trim((string) $external);

            if ($external === '' || strlen($external) > 64) {
                continue;
            }

            $reference = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('external_order_id', $external)
                ->value('reference');

            if ($reference) {
                return (string) $reference;
            }
        }

        return null;
    }

    public function exchangeCustomerIdentifier(string $identifier): array
    {
        return ['success' => false, 'unsupported' => true, 'message' => 'Not applicable to Awash merchant debit.'];
    }

    public function clientConfig(): array
    {
        $client = $this->config['client'] ?? [];

        return [
            'slug' => $this->slug(),
            'name' => $this->displayName(),
            // Show order_payload.message ("approve on your phone"), then poll.
            'kind' => $client['kind'] ?? 'ussd_push',
            'bridge' => null,
            'app_code' => null,
            'stage' => (string) $this->setting('STAGE', 'uat') ?: 'uat',
            'supports_identity' => false,
        ];
    }
}

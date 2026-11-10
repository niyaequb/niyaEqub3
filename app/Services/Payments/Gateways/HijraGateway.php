<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\HandlesCustomerReturn;
use App\Contracts\PaymentGateway;
use App\Models\EqubPayment;
use App\Models\PaymentGatewayOrder;
use App\Services\EnvService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hijra Bank payment gateway: USSD push.
 *
 * HOW THIS DIFFERS FROM THE OTHERS
 *
 * Dashen is a super-app (signed order over a JS bridge) and EthSwitch is a
 * hosted card page. Hijra is neither: the member never leaves the Niya app.
 *
 *     POST /payments           we send phone + amount + our reference (EQUB-...)
 *     Hijra                    checks the phone has an active account and funds,
 *                              then pushes a USSD prompt to that phone
 *     member                   enters their PIN on the prompt and picks the account
 *     webhook                  Hijra calls /payment/hijra/callback
 *     GET /payments/{ref}      we ASK Hijra the status, and only then credit
 *
 * The webhook is never trusted on its own: Hijra's guide does not define a
 * signature for it. It only makes us ask GET /payments/{ref} early, through
 * the same PaymentSettlementService::reconcile() the five-minute sweep uses.
 *
 * Our reference is Hijra's idempotency key (paymentReference), so re-sending
 * an order for the same reference returns the existing payment (HTTP 200)
 * rather than pushing a second prompt and debiting twice.
 *
 * FAILING CLOSED, AS EVERY GATEWAY HERE MUST
 *
 *     COMPLETED                              paid, so credit it (after checks)
 *     FAILED, CANCELLED, TIMED_OUT           final, not paid: mark failed
 *     RECEIVED, USSD_INITIATED, PROCESSING   leave pending
 *     404 PAYMENT_NOT_FOUND                  never reached Hijra
 *     anything else, or no answer            leave pending
 *
 * Before crediting: the reference Hijra reports must be ours and the amount
 * must be what we sent.
 *
 * SETTINGS (settings table first, .env second, through EnvService)
 *
 *     HIJRA_TOKEN_URL       https://{oauth-host}/oauth2/token
 *     HIJRA_BASE_URL        https://{api-host}/pps/1.0.0/api/v1
 *     HIJRA_CLIENT_ID       issued by Hijra
 *     HIJRA_CLIENT_SECRET   issued by Hijra
 *     HIJRA_SHOW_IN_APPS    false hides it from the apps (hijra:test still works)
 *     HIJRA_USSD_MINUTES    how long a prompt may stay unanswered (default 10)
 *     HIJRA_TIMEOUT_SECONDS HTTP timeout (default 30)
 */
class HijraGateway implements HandlesCustomerReturn, PaymentGateway
{
    protected const SETTLED = ['COMPLETED'];

    protected const FINAL_FAILED = ['FAILED', 'CANCELLED', 'TIMED_OUT'];

    protected const IN_FLIGHT = ['RECEIVED', 'USSD_INITIATED', 'PROCESSING'];

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
        return 'hijra';
    }

    public function displayName(): string
    {
        return $this->config['name'] ?? 'Hijra Bank';
    }

    protected function envPrefix(): string
    {
        return $this->config['env_prefix'] ?? 'HIJRA';
    }

    protected function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->env->get($this->envPrefix().'_'.$key, $default);

        return $value === null ? $default : trim((string) $value);
    }

    public function isConfigured(): bool
    {
        foreach (['TOKEN_URL', 'BASE_URL', 'CLIENT_ID', 'CLIENT_SECRET'] as $key) {
            if ((string) $this->setting($key) === '') {
                return false;
            }
        }

        return true;
    }

    /** GET /payments/{ref} needs nothing beyond what initiating needs. */
    public function canVerifySettlement(): bool
    {
        return $this->isConfigured();
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->setting('BASE_URL'), '/').'/'.ltrim($path, '/');
    }

    protected function ussdMinutes(): int
    {
        return max(2, (int) $this->setting('USSD_MINUTES', '10'));
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) $this->setting('TIMEOUT_SECONDS', '30') ?: 30)
            ->connectTimeout(10);

        // Only for a UAT host whose certificate this server cannot validate.
        // Never set it in production.
        if (filter_var($this->setting('INSECURE_SKIP_TLS_VERIFY', 'false'), FILTER_VALIDATE_BOOL)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    // ---------------------------------------------------------------------
    // OAuth 2.0 client credentials (HTTP Basic, grant_type in the query)
    // ---------------------------------------------------------------------

    protected function tokenCacheKey(): string
    {
        return 'payments:hijra:token:'.sha1((string) $this->setting('CLIENT_ID').'|'.(string) $this->setting('TOKEN_URL'));
    }

    public function accessToken(bool $fresh = false): string
    {
        $key = $this->tokenCacheKey();

        if (! $fresh && ($cached = Cache::get($key))) {
            return (string) $cached;
        }

        $url = (string) $this->setting('TOKEN_URL');
        $url .= (str_contains($url, '?') ? '&' : '?').'grant_type=client_credentials';

        try {
            $response = $this->http()
                ->withBasicAuth((string) $this->setting('CLIENT_ID'), (string) $this->setting('CLIENT_SECRET'))
                ->asForm()
                ->post($url, ['grant_type' => 'client_credentials']);
        } catch (\Throwable $e) {
            Log::error('Hijra token endpoint could not be reached', ['error' => $e->getMessage()]);

            throw new \RuntimeException('Could not reach Hijra Bank to authenticate.');
        }

        $token = (string) ($response->json('access_token') ?? '');

        if (! $response->successful() || $token === '') {
            Log::error('Hijra refused the token request', [
                'http' => $response->status(),
                // No secret is in the response; the body says why (bad client, wrong host).
                'body' => $response->json() ?? mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException('Hijra Bank did not issue an access token (HTTP '.$response->status().').');
        }

        // Renew a minute early so a token never expires mid-request.
        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        Cache::put($key, $token, $ttl);

        return $token;
    }

    /**
     * One authorised call, retried once with a fresh token on 401.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function api(string $method, string $path, ?array $json = null): \Illuminate\Http\Client\Response
    {
        $send = function (string $token) use ($method, $path, $json) {
            $request = $this->http()->withToken($token);

            return $method === 'GET'
                ? $request->get($this->url($path))
                : $request->asJson()->post($this->url($path), $json ?? []);
        };

        $response = $send($this->accessToken());

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $response = $send($this->accessToken(true));
        }

        return $response;
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /**
     * Ask Hijra to push a USSD prompt to the payer's phone.
     *
     * Throws on any refusal (no Hijra account on that phone, insufficient
     * funds, telecom down). EqubOrderService logs it and shows the member a
     * plain message; nothing was charged.
     */
    public function createOrder(string $reference, float $amount, string $narration): array
    {
        $amount = round($amount, 2);

        if ($amount < 0.01) {
            throw new \RuntimeException('Hijra order amount must be at least 0.01.');
        }

        $phone = $this->payerPhone($reference);

        if ($phone === null) {
            throw new \RuntimeException('No valid phone number on the paying member for '.$reference.'.');
        }

        $minor = (int) round($amount * 100);

        $existing = PaymentGatewayOrder::query()
            ->where('gateway', $this->slug())
            ->where('reference', $reference)
            ->first();

        $body = [
            'paymentReference' => $reference,
            'customerPhone' => $phone,
            'amount' => $amount,
            'currency' => 'ETB',
            'additionalParams' => [
                'orderId' => $reference,
                'notes' => $this->narration($narration),
            ],
        ];

        try {
            $response = $this->api('POST', 'payments', $body);
        } catch (\Throwable $e) {
            Log::error('Hijra payment initiation could not be sent', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not reach Hijra Bank to start this payment.');
        }

        $data = (array) ($response->json() ?? []);

        // 202 = new payment accepted, 200 = idempotency hit (same reference).
        if (! in_array($response->status(), [200, 202], true)) {
            Log::error('Hijra refused the payment initiation', [
                'reference' => $reference,
                'http' => $response->status(),
                'body' => $data ?: mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException(
                'Hijra Bank did not accept the payment ('
                .($data['error'] ?? 'HTTP '.$response->status()).': '
                .($this->firstDetail($data) ?? $data['message'] ?? 'no reason given').').'
            );
        }

        // 200 means Hijra already has this reference. If that payment is over
        // (cancelled, failed, expired) no new prompt is sent, so say so instead
        // of leaving the member waiting for a USSD prompt that never comes.
        $returned = strtoupper((string) ($data['status'] ?? ''));
        if ($response->status() === 200 && in_array($returned, self::FINAL_FAILED, true)) {
            throw new \RuntimeException('Hijra already closed reference '.$reference.' as '.$returned.'; a new payment needs a new reference.');
        }

        $order = PaymentGatewayOrder::updateOrCreate(
            ['gateway' => $this->slug(), 'reference' => $reference],
            [
                'external_order_id' => (string) ($data['correlationId'] ?? $existing?->external_order_id ?? ''),
                'checkout_url' => null,
                'amount_minor' => $minor,
                'currency' => 'ETB',
                'status' => strtolower((string) ($data['status'] ?? 'received')),
                'register_response' => $data,
            ]
        );

        Log::info('Hijra USSD push requested', [
            'reference' => $reference,
            'correlation_id' => $order->external_order_id,
            'phone' => $this->mask($phone),
            'amount' => $amount,
            'http' => $response->status(),
        ]);

        return [
            'type' => 'ussd_push',
            'reference' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'ETB',
            'phone' => $this->mask($phone),
            'expires_in_seconds' => $this->ussdMinutes() * 60,
            'message' => 'Check your phone '.$this->mask($phone).' and enter your Hijra Bank PIN to approve '
                .number_format($amount, 2).' ETB.',
        ];
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

    /** Hijra expect ^\+?[0-9]{10,15}$; their sample is 2519XXXXXXXX. */
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
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}\-_.,: ]+/u', ' ', (string) $value)));

        return $clean === '' ? 'Niya Equb contribution' : mb_substr($clean, 0, 100);
    }

    /** @param  array<string, mixed>  $data */
    protected function firstDetail(array $data): ?string
    {
        $details = $data['details'] ?? null;

        if (is_array($details) && $details !== []) {
            return (string) reset($details);
        }

        return null;
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
     * Ask Hijra whether this payment completed.
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

        try {
            $response = $this->api('GET', 'payments/'.rawurlencode($reference));
        } catch (\Throwable $e) {
            Log::error('Hijra status check could not reach Hijra', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'pending' => true, 'message' => 'Could not reach Hijra Bank to verify this payment.'];
        }

        $body = (array) ($response->json() ?? []);
        $data = ['response' => $body];

        if ($response->status() === 404) {
            $order?->forceFill(['status' => 'not_found', 'last_checked_at' => now(), 'status_response' => $body])->save();

            return [
                'success' => false,
                'pending' => true,
                'not_found' => true,
                'answered' => true,
                'message' => 'Hijra Bank has no payment for this reference.',
                'data' => $data,
            ];
        }

        $status = strtoupper(trim((string) ($body['status'] ?? '')));

        if (! $response->successful() || $status === '') {
            Log::warning('Hijra status check was inconclusive; leaving pending', [
                'reference' => $reference,
                'http' => $response->status(),
                'body' => $body ?: mb_substr($response->body(), 0, 500),
            ]);

            return ['success' => false, 'pending' => true, 'message' => 'Hijra Bank could not look this payment up right now.', 'data' => $data];
        }

        $order?->forceFill([
            'status' => strtolower($status),
            'external_order_id' => $order->external_order_id ?: (string) ($body['correlationId'] ?? ''),
            'last_checked_at' => now(),
            'status_response' => $body,
        ])->save();

        if (in_array($status, self::SETTLED, true)) {
            $reportedRef = (string) ($body['paymentReference'] ?? '');
            $reportedAmount = $body['amount'] ?? null;
            $reportedCurrency = strtoupper((string) ($body['currency'] ?? 'ETB'));

            $refMismatch = $reportedRef !== '' && strcasecmp($reportedRef, $reference) !== 0;
            $amountMismatch = $order !== null && is_numeric($reportedAmount)
                && (int) round(((float) $reportedAmount) * 100) !== (int) $order->amount_minor;
            $currencyMismatch = $reportedCurrency !== 'ETB';

            if ($refMismatch || $amountMismatch || $currencyMismatch) {
                Log::critical('Hijra reports COMPLETED but the payment does not match ours; NOT credited', [
                    'reference' => $reference,
                    'reported_reference' => $reportedRef,
                    'reported_amount' => $reportedAmount,
                    'reported_currency' => $reportedCurrency,
                    'registered_amount_minor' => $order?->amount_minor,
                ]);

                return [
                    'success' => false,
                    'pending' => true,
                    'message' => 'Hijra Bank reported this payment with details that do not match the order.',
                    'data' => $data,
                ];
            }

            Log::info('Hijra payment verified', [
                'reference' => $reference,
                'transaction_reference' => $body['transactionReference'] ?? null,
            ]);

            return ['success' => true, 'data' => $data, 'message' => 'Payment verified successfully'];
        }

        if (in_array($status, self::FINAL_FAILED, true)) {
            return [
                'success' => false,
                'status' => $status,
                'message' => match ($status) {
                    'CANCELLED' => 'The payment was cancelled on the phone.',
                    'TIMED_OUT' => 'The USSD prompt expired before it was approved.',
                    default => (string) ($body['failureReason'] ?? 'Hijra Bank could not complete the payment.'),
                },
                'data' => $data,
            ];
        }

        // Hijra's UAT leaves a rejected request in RECEIVED with a
        // failureReason (seen: CUSTOMER_NOT_FOUND) instead of moving it to
        // FAILED. No prompt is ever sent for it, so it is final: say so now
        // rather than leave the member waiting for a USSD prompt.
        $failureReason = trim((string) ($body['failureReason'] ?? ''));
        if ($failureReason !== '' && ! in_array($status, self::SETTLED, true)) {
            Log::warning('Hijra rejected the payment', ['reference' => $reference, 'status' => $status, 'reason' => $failureReason]);

            return [
                'success' => false,
                'status' => 'FAILED',
                'message' => match ($failureReason) {
                    'CUSTOMER_NOT_FOUND' => 'This phone number has no Hijra Bank account.',
                    'INSUFFICIENT_FUNDS', 'INSUFFICIENT_BALANCE' => 'Not enough balance in the Hijra Bank account.',
                    default => 'Hijra Bank could not complete the payment ('.$failureReason.').',
                },
                'data' => $data,
            ];
        }

        // RECEIVED / USSD_INITIATED / PROCESSING, or a status Hijra add later.
        // Money may be moving: never fail it from here. Hijra time the prompt
        // out themselves (TIMED_OUT), which is the answer we wait for.
        if (! in_array($status, self::IN_FLIGHT, true)) {
            Log::warning('Hijra returned an unknown status; leaving pending', ['reference' => $reference, 'status' => $status]);
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
            // Hijra send local time without an offset: 2026-10-01T05:55:12.
            $paidAt = filled($body['updatedAt'] ?? null)
                ? Carbon::parse((string) $body['updatedAt'], (string) $this->setting('TIMEZONE', 'Africa/Addis_Ababa'))
                : null;
        } catch (\Throwable) {
            $paidAt = null;
        }

        return [
            'transaction_id' => $body['transactionReference'] ?? null,
            'bank_reference' => $body['correlationId'] ?? null,
            'paid_at' => $paidAt,
            'amount' => is_numeric($body['amount'] ?? null) ? number_format((float) $body['amount'], 2, '.', '') : null,
            'payload' => $data === [] ? null : $data,
        ];
    }

    // ---------------------------------------------------------------------
    // Inbound (webhook)
    // ---------------------------------------------------------------------

    /**
     * Hijra's guide does not define a webhook signature. If they provide a
     * shared secret, set HIJRA_CALLBACK_SECRET and it is checked here.
     * Either way nothing is settled on the webhook itself.
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
        return $this->config['signature_headers'] ?? ['x-hijra-signature'];
    }

    public function extractReference(array $payload): ?string
    {
        return $this->referenceFromReturn($payload);
    }

    /**
     * Which of our references a webhook is about. Accepted only if we sent it
     * to Hijra, so a forged call can at most make us ask Hijra a question.
     */
    public function referenceFromReturn(array $params): ?string
    {
        $candidates = [
            $params['paymentReference'] ?? null,
            data_get($params, 'data.paymentReference'),
            data_get($params, 'payment.paymentReference'),
        ];

        foreach ($candidates as $reference) {
            $reference = trim((string) $reference);

            if ($reference === '' || strlen($reference) > 64) {
                continue;
            }

            $known = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('reference', $reference)
                ->value('reference');

            if ($known) {
                return (string) $known;
            }
        }

        $correlationId = trim((string) ($params['correlationId'] ?? data_get($params, 'data.correlationId') ?? ''));

        if ($correlationId !== '' && strlen($correlationId) <= 64) {
            $reference = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('external_order_id', $correlationId)
                ->value('reference');

            return $reference ? (string) $reference : null;
        }

        return null;
    }

    public function exchangeCustomerIdentifier(string $identifier): array
    {
        return ['success' => false, 'unsupported' => true, 'message' => 'Not applicable to USSD push.'];
    }

    public function clientConfig(): array
    {
        $client = $this->config['client'] ?? [];

        return [
            'slug' => $this->slug(),
            'name' => $this->displayName(),
            // Show order_payload.message ("check your phone"), then poll the contribution.
            'kind' => $client['kind'] ?? 'ussd_push',
            'bridge' => null,
            'app_code' => null,
            'stage' => (string) $this->setting('STAGE', 'uat') ?: 'uat',
            'supports_identity' => false,
        ];
    }
}

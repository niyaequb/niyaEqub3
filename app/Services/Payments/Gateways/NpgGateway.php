<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\HandlesCustomerReturn;
use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayOrder;
use App\Services\EnvService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * EthSwitch National Payment Gateway (NPG / EPG): card payments.
 *
 * HOW THIS DIFFERS FROM DASHEN
 *
 * Dashen is a super-app: the order is signed here and handed to the bank's own
 * app over a JavaScript bridge. NPG is a hosted checkout, the classic web flow:
 *
 *     register.do     we register the order and get back { orderId, formUrl }
 *     formUrl         the member opens it and types card + OTP on EthSwitch's page
 *     returnUrl       EthSwitch sends the browser back to /payment/npg/return?orderId=
 *     getOrderStatus  we ASK EthSwitch whether it was paid, and only then credit
 *
 * So there is no signing and no client secret. The merchant API user and
 * password stay on this server and never reach an app.
 *
 * THE ONE PIECE OF STATE THIS GATEWAY KEEPS
 *
 * register.do answers with EthSwitch's own orderId (a UUID), and
 * getOrderStatus.do is keyed by that id, not by our order number. The rest of
 * the platform only knows our reference (EQUB-..., or EQUB-B... for a batch),
 * so the pair is kept in payment_gateway_orders. That table knows nothing
 * about Equb: a reference, the bank's id, the checkout URL, the amount sent.
 *
 * THE AMOUNT IS IN MINOR UNITS
 *
 * The EthSwitch Merchant API says `amount` is in the minor denomination, so
 * 700.00 ETB is sent as 70000. NPG_AMOUNT_MULTIPLIER (default 100) exists
 * because that is worth confirming on the first UAT order: open the formUrl
 * and check the amount the page shows. If EthSwitch say whole birr, set it to 1.
 *
 * FAILING CLOSED, AS EVERY GATEWAY HERE MUST
 *
 *     OrderStatus 2              paid, so credit it
 *     OrderStatus 3 or 6         reversed / declined, so mark it failed
 *     OrderStatus 0              registered, never paid; abandoned once old
 *     anything else, or no answer   leave it pending
 *
 * And two integrity checks before anything is credited: the order number
 * EthSwitch report must be ours, and the amount must be what we registered.
 */
class NpgGateway implements HandlesCustomerReturn, PaymentGateway
{
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
        return 'npg';
    }

    public function displayName(): string
    {
        return $this->config['name'] ?? 'Card (EthSwitch)';
    }

    protected function envPrefix(): string
    {
        return $this->config['env_prefix'] ?? 'NPG';
    }

    protected function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->env->get($this->envPrefix().'_'.$key, $default);

        return $value === null ? $default : trim((string) $value);
    }

    public function isConfigured(): bool
    {
        foreach (['BASE_URL', 'USERNAME', 'PASSWORD'] as $key) {
            if ((string) $this->setting($key) === '') {
                return false;
            }
        }

        return true;
    }

    /** getOrderStatus.do needs nothing beyond what registering needs. */
    public function canVerifySettlement(): bool
    {
        return $this->isConfigured();
    }

    protected function endpoint(string $pathKey, string $default): string
    {
        return rtrim((string) $this->setting('BASE_URL'), '/')
            .'/'.ltrim((string) $this->setting($pathKey, $default), '/');
    }

    /** ISO 4217 numeric. 230 is ETB. */
    protected function currency(): string
    {
        return (string) $this->setting('CURRENCY', '230') ?: '230';
    }

    protected function multiplier(): int
    {
        $value = (int) $this->setting('AMOUNT_MULTIPLIER', '100');

        return $value > 0 ? $value : 100;
    }

    /** ETB to the integer EthSwitch expects. Never through a float that is not rounded. */
    public function toMinor(float $amount): int
    {
        return (int) round($amount * $this->multiplier());
    }

    public function fromMinor(int|string|null $minor): ?string
    {
        if ($minor === null || $minor === '' || ! is_numeric($minor)) {
            return null;
        }

        return number_format(((int) $minor) / $this->multiplier(), 2, '.', '');
    }

    public function returnUrl(): string
    {
        $configured = (string) $this->setting('RETURN_URL');

        return $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/payment/'.$this->slug().'/return';
    }

    /**
     * How long a registered order may sit unpaid before we stop treating it as
     * "the member might still be typing their card number".
     */
    protected function sessionMinutes(): int
    {
        return max(1, (int) $this->setting('SESSION_MINUTES', '20'));
    }

    /** The merchant credentials every EPG request carries. */
    protected function credentials(): array
    {
        return [
            'userName' => (string) $this->setting('USERNAME'),
            'password' => (string) $this->setting('PASSWORD'),
        ];
    }

    /**
     * POST to EthSwitch.
     *
     * PLAIN PHP CURL, NOT Http:: (Guzzle). EthSwitch's server sends response
     * headers Guzzle refuses to parse ("An error was encountered while
     * creating the response"), so every call failed before the body could be
     * read, although curl on the same server got a perfectly good answer.
     * The body is wrapped in a header-less PSR-7 response so the rest of this
     * class keeps using json(), successful() and status() as before.
     *
     * Form-encoded body by default, which is what their Merchant API
     * recommends so the password does not end up in URL logs. Their own
     * Postman collection sends the same fields in the query string instead;
     * NPG_REQUEST_STYLE=query switches to that if UAT ever refuses the body.
     *
     * @param  array<string, scalar>  $params
     */
    protected function call(string $url, array $params): \Illuminate\Http\Client\Response
    {
        $query = strtolower((string) $this->setting('REQUEST_STYLE', 'form')) === 'query';

        $handle = curl_init($query ? $url.'?'.http_build_query($params) : $url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $query ? '' : http_build_query($params),
            CURLOPT_HTTPHEADER => [
                // Required by the Merchant API on every web API request.
                'Accept: application/json, text/javascript, */*',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(5, (int) $this->setting('TIMEOUT_SECONDS', '30')),
        ]);

        // Only for a UAT host whose certificate the server cannot validate.
        // Never set this in production.
        if (filter_var($this->setting('INSECURE_SKIP_TLS_VERIFY', 'false'), FILTER_VALIDATE_BOOL)) {
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($handle);

        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new \RuntimeException('cURL error talking to EthSwitch: '.$error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], (string) $body)
        );
    }

    /**
     * Read a field however EthSwitch chose to capitalise it.
     *
     * getOrderStatus.do answers `OrderStatus`, `ErrorCode`, `Amount`; the
     * extended variant answers `orderStatus`, `errorCode`, `amount`. Both are
     * accepted so moving between them is a config change, not a code change.
     */
    protected function field(array $data, string ...$names): mixed
    {
        foreach ($names as $name) {
            $value = data_get($data, $name);

            if ($value !== null && $value !== '') {
                return $value;
            }

            foreach ($data as $key => $candidate) {
                if (is_string($key) && strcasecmp($key, $name) === 0 && $candidate !== null && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /**
     * Register one order and return the checkout the member must open.
     *
     * Throws on any refusal. EqubOrderService turns that into a logged error
     * and a plain message for the member, and the controller deletes the
     * pending rows, because nothing was charged.
     */
    public function createOrder(string $reference, float $amount, string $narration): array
    {
        $minor = $this->toMinor($amount);

        if ($minor <= 0) {
            throw new \RuntimeException('NPG order amount must be greater than zero.');
        }

        $existing = PaymentGatewayOrder::query()
            ->where('gateway', $this->slug())
            ->where('reference', $reference)
            ->first();

        // An operator re-presenting an abandoned order. EthSwitch refuse a
        // second register.do for the same order number, so hand back the
        // checkout already issued for it.
        if ($existing && filled($existing->checkout_url) && (int) $existing->amount_minor === $minor) {
            return $this->payloadFor($existing);
        }

        $params = $this->credentials() + [
            'orderNumber' => $reference,
            'amount' => $minor,
            'currency' => $this->currency(),
            'returnUrl' => $this->returnUrl(),
            'description' => $this->narration($narration),
            'language' => (string) $this->setting('LANGUAGE', 'en') ?: 'en',
        ];

        // Optional: only sent when configured, so a gateway that does not know
        // these parameters is never sent them.
        if (($fail = (string) $this->setting('FAIL_URL')) !== '') {
            $params['failUrl'] = $fail;
        }

        if (($timeout = (int) $this->setting('SESSION_TIMEOUT_SECONDS', '0')) > 0) {
            $params['sessionTimeoutSecs'] = $timeout;
        }

        try {
            $response = $this->call($this->endpoint('REGISTER_PATH', 'register.do'), $params);
        } catch (\Throwable $e) {
            Log::error('NPG register.do could not be reached', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not reach EthSwitch to start this payment.');
        }

        $data = (array) ($response->json() ?? []);
        $errorCode = (string) ($this->field($data, 'errorCode') ?? '0');
        $orderId = (string) ($this->field($data, 'orderId') ?? '');
        $formUrl = (string) ($this->field($data, 'formUrl') ?? '');

        if (! $response->successful() || $errorCode !== '0' || $orderId === '' || $formUrl === '') {
            // The whole body: register.do errors are short and carry no card
            // data, and "errorCode 5, Access denied" is the line that tells you
            // the server's IP is not whitelisted yet.
            Log::error('NPG register.do refused the order', [
                'reference' => $reference,
                'http' => $response->status(),
                'body' => $data ?: $response->body(),
            ]);

            throw new \RuntimeException(
                'EthSwitch did not accept the order (error '.$errorCode.': '
                .($this->field($data, 'errorMessage') ?? 'HTTP '.$response->status()).').'
            );
        }

        $order = PaymentGatewayOrder::updateOrCreate(
            ['gateway' => $this->slug(), 'reference' => $reference],
            [
                'external_order_id' => $orderId,
                'checkout_url' => $formUrl,
                'amount_minor' => $minor,
                'currency' => $this->currency(),
                'status' => 'registered',
                'register_response' => $data,
            ]
        );

        Log::info('NPG order registered', [
            'reference' => $reference,
            'order_id' => $orderId,
            'amount_minor' => $minor,
        ]);

        return $this->payloadFor($order);
    }

    /**
     * What the app receives as `order_payload`.
     *
     * Nothing in it is secret. The app opens checkout_url in a browser or web
     * view, and polls GET /api/member/equb-payments/{id} until it settles.
     */
    protected function payloadFor(PaymentGatewayOrder $order): array
    {
        return [
            'type' => 'web_checkout',
            'checkout_url' => $order->checkout_url,
            'gateway_order_id' => $order->external_order_id,
            'reference' => $order->reference,
            'return_url' => $this->returnUrl(),
            'amount' => $this->fromMinor($order->amount_minor),
            'currency' => 'ETB',
        ];
    }

    protected function narration(?string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_. ]+/', ' ', (string) $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean), ' .');

        return $clean === '' ? 'Equb contribution payment' : mb_substr($clean, 0, 99);
    }

    /** A hosted checkout needs no client credentials. */
    public function authPayload(?string $customerIdentifier = null): array
    {
        return [];
    }

    // ---------------------------------------------------------------------
    // Settlement
    // ---------------------------------------------------------------------

    /**
     * Ask EthSwitch whether this order was paid.
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

        // We never registered this reference with EthSwitch (register.do
        // failed, or the row was created and the call never made). No money
        // can have moved against an order the bank never issued.
        if (! $order || blank($order->external_order_id)) {
            return [
                'success' => false,
                'pending' => true,
                'not_found' => true,
                'answered' => true,
                'message' => 'EthSwitch has no order for this reference.',
            ];
        }

        try {
            $response = $this->call(
                $this->endpoint('STATUS_PATH', 'getOrderStatus.do'),
                $this->credentials() + [
                    'orderId' => $order->external_order_id,
                    'language' => (string) $this->setting('LANGUAGE', 'en') ?: 'en',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NPG status check could not reach EthSwitch', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'pending' => true,
                'message' => 'Could not reach EthSwitch to verify this payment.',
            ];
        }

        $body = (array) ($response->json() ?? []);
        $data = ['order_id' => $order->external_order_id, 'response' => $body];

        $status = $this->field($body, 'orderStatus');
        $status = $status === null ? null : (string) $status;
        $errorCode = (string) ($this->field($body, 'errorCode') ?? '0');

        $order->forceFill([
            'status' => $status === null ? 'unknown' : 'status_'.$status,
            'last_checked_at' => now(),
            'status_response' => $body,
        ])->save();

        // EthSwitch could not find this orderId: it never existed there.
        if ($status === null && $errorCode === '6') {
            return [
                'success' => false,
                'pending' => true,
                'not_found' => true,
                'answered' => true,
                'message' => 'EthSwitch has no record of this order.',
                'data' => $data,
            ];
        }

        // No status at all: bad credentials, IP not whitelisted, an outage.
        // Says nothing about whether the customer paid.
        if (! $response->successful() || $status === null) {
            Log::warning('NPG status check was inconclusive; leaving pending', [
                'reference' => $reference,
                'http' => $response->status(),
                'body' => $body ?: $response->body(),
            ]);

            return [
                'success' => false,
                'pending' => true,
                'message' => 'EthSwitch could not look this payment up right now.',
                'data' => $data,
            ];
        }

        if (in_array($status, $this->statusList('SETTLED_STATUSES', '2'), true)) {
            // Integrity before money. A paid answer for a different order
            // number, or for a different amount, is not credited: it is logged
            // loudly and left for a person.
            $reportedNumber = (string) ($this->field($body, 'orderNumber', 'OrderNumber') ?? '');
            $reportedAmount = $this->field($body, 'amount', 'Amount');

            $numberMismatch = $reportedNumber !== '' && $reportedNumber !== $order->reference;
            $amountMismatch = is_numeric($reportedAmount) && (int) $reportedAmount !== (int) $order->amount_minor;

            if ($numberMismatch || $amountMismatch) {
                Log::critical('NPG reports PAID but the order does not match ours; NOT credited', [
                    'reference' => $reference,
                    'reported_order_number' => $reportedNumber,
                    'reported_amount' => $reportedAmount,
                    'registered_amount' => $order->amount_minor,
                ]);

                return [
                    'success' => false,
                    'pending' => true,
                    'message' => 'EthSwitch reported this payment with details that do not match the order.',
                    'data' => $data,
                ];
            }

            Log::info('NPG payment verified', [
                'reference' => $reference,
                'order_id' => $order->external_order_id,
                'approval_code' => $this->field($body, 'approvalCode', 'cardAuthInfo.approvalCode'),
            ]);

            return ['success' => true, 'data' => $data, 'message' => 'Payment verified successfully'];
        }

        if (in_array($status, $this->statusList('FAILED_STATUSES', '3,6'), true)) {
            Log::warning('NPG reports this payment as declined or reversed', [
                'reference' => $reference,
                'status' => $status,
                'body' => $body,
            ]);

            return [
                'success' => false,
                'status' => $status,
                'message' => (string) ($this->field($body, 'errorMessage', 'actionCodeDescription') ?: 'The card payment was not successful.'),
                'data' => $data,
            ];
        }

        // 0: registered, never paid. While the session is young the member may
        // still be on the card page; after that it is abandoned, and reporting
        // it as not_found lets settlement retire it and lets the member retry.
        if ($status === '0') {
            $abandoned = $order->created_at !== null
                && $order->created_at->lt(now()->subMinutes($this->sessionMinutes()));

            return [
                'success' => false,
                'pending' => true,
                'not_found' => $abandoned,
                'answered' => true,
                'status' => $status,
                'message' => $abandoned
                    ? 'This card payment was started but never completed.'
                    : 'Waiting for the card payment to be completed.',
                'data' => $data,
            ];
        }

        // 1 (held, two-phase), 5 (3-D Secure in progress), 4 (refunded) or
        // anything new: money may be moving, or a person needs to look.
        Log::warning('NPG returned a status that is neither paid nor declined; leaving pending', [
            'reference' => $reference,
            'status' => $status,
        ]);

        return [
            'success' => false,
            'pending' => true,
            'status' => $status,
            'message' => 'EthSwitch has not confirmed this payment yet.',
            'data' => $data,
        ];
    }

    /** @return array<int, string> */
    protected function statusList(string $key, string $default): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->setting($key, $default) ?: $default))
        ), fn ($v) => $v !== ''));
    }

    public function extractSettlement(array $data): array
    {
        $body = (array) ($data['response'] ?? []);

        $paidAt = $this->field($body, 'date', 'authDateTime', 'depositedDate');

        try {
            // EthSwitch (SmartVista) send epoch milliseconds.
            $paidAt = match (true) {
                is_numeric($paidAt) && (int) $paidAt > 100000000000 => Carbon::createFromTimestampMs((int) $paidAt),
                is_numeric($paidAt) => Carbon::createFromTimestamp((int) $paidAt),
                is_string($paidAt) && $paidAt !== '' => Carbon::parse($paidAt),
                default => null,
            };
        } catch (\Throwable) {
            $paidAt = null;
        }

        return [
            'transaction_id' => $data['order_id'] ?? null,
            'bank_reference' => $this->field($body, 'approvalCode', 'authRefNum', 'cardAuthInfo.approvalCode', 'authCode'),
            'paid_at' => $paidAt,
            'amount' => $this->fromMinor($this->field($body, 'amount', 'Amount')),
            'payer_name' => $this->field($body, 'cardholderName', 'cardAuthInfo.cardholderName'),
            // Masked by EthSwitch (e.g. 923142**6347). Never the full PAN.
            'payer_account' => $this->field($body, 'Pan', 'pan', 'cardAuthInfo.pan', 'cardAuthInfo.maskedPan'),
            'payload' => $data === [] ? null : $data,
        ];
    }

    // ---------------------------------------------------------------------
    // Inbound
    // ---------------------------------------------------------------------

    /**
     * NPG does not sign a body we can check, and nothing is settled on an
     * inbound message anyway: the return and callback routes only trigger a
     * status check that WE make. A shared secret can be set if EthSwitch ever
     * provide a signed callback.
     */
    public function verifyNotificationSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) $this->setting('CALLBACK_SECRET');

        if ($secret === '' || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }

    public function signatureHeaders(): array
    {
        return $this->config['signature_headers'] ?? ['x-npg-signature'];
    }

    public function extractReference(array $payload): ?string
    {
        return $this->referenceFromReturn($payload);
    }

    /**
     * Which of our references a returning browser or a callback is about.
     *
     * EthSwitch append their own orderId (mdOrder on callbacks). Our order
     * number is accepted too, but only if we actually registered it.
     */
    public function referenceFromReturn(array $params): ?string
    {
        $orderId = trim((string) ($params['orderId'] ?? $params['mdOrder'] ?? $params['mdorder'] ?? ''));

        if ($orderId !== '' && strlen($orderId) <= 64) {
            $reference = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('external_order_id', $orderId)
                ->value('reference');

            if ($reference) {
                return (string) $reference;
            }
        }

        $number = trim((string) ($params['orderNumber'] ?? ''));

        if ($number !== '' && strlen($number) <= 64) {
            $known = PaymentGatewayOrder::query()
                ->where('gateway', $this->slug())
                ->where('reference', $number)
                ->exists();

            return $known ? $number : null;
        }

        return null;
    }

    public function exchangeCustomerIdentifier(string $identifier): array
    {
        return ['success' => false, 'unsupported' => true, 'message' => 'Not applicable to card payments.'];
    }

    public function clientConfig(): array
    {
        $client = $this->config['client'] ?? [];

        return [
            'slug' => $this->slug(),
            'name' => $this->displayName(),
            // Open order_payload.checkout_url, then poll the contribution.
            'kind' => $client['kind'] ?? 'web_checkout',
            'bridge' => null,
            'app_code' => null,
            'stage' => (string) $this->setting('STAGE', 'uat') ?: 'uat',
            'supports_identity' => false,
        ];
    }
}

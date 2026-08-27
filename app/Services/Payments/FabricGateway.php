<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Services\EnvService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared implementation for banks on the "fabric" scheme.
 *
 * WHAT THE FABRIC SCHEME IS
 *
 * Most Ethiopian bank super-apps expose the same request shape, inherited from
 * the same upstream platform:
 *
 *     { timestamp, nonce_str, method, version, biz_content, sign, stage,
 *       confirmpayload }
 *
 * `biz_content` carries the order. `sign` is an RSA-encrypted subset of the
 * request, made with a public key issued at onboarding. `confirmpayload` is an
 * HMAC-SHA256 over the whole request, made with the app secret. The bank
 * rejects anything whose signed content disagrees with the rest of the body.
 *
 * Dashen is the first bank on it. CBE and Awash are expected to be the same
 * shape with different names and endpoints, which is why this class exists
 * before there is a second implementation to share it: the alternative is
 * discovering the commonality on bank number two and refactoring a live
 * payment path, which is the worst possible time.
 *
 * WHAT A SUBCLASS ACTUALLY SUPPLIES
 *
 * A slug, a display name, and — where the bank differs — an override. The
 * defaults here are the scheme as documented; a bank that names its query
 * method differently or nests its reference somewhere else overrides one
 * method rather than reimplementing the crypto.
 *
 * WHAT IS DELIBERATELY NOT SHARED
 *
 * Credentials. Every value is read through `env_prefix`, so DASHEN_APP_SECRET
 * and CBE_APP_SECRET can never be confused for one another, and a
 * misconfigured bank fails on its own rather than silently signing with
 * another bank's key.
 */
abstract class FabricGateway implements PaymentGateway
{
    protected EnvService $env;

    /** @var array<string, mixed> The gateway's block from config/payments.php. */
    protected array $config;

    public function __construct(EnvService $env, array $config = [])
    {
        $this->env = $env;
        $this->config = $config;
    }

    // ---------------------------------------------------------------------
    // Identity
    // ---------------------------------------------------------------------

    abstract public function slug(): string;

    public function displayName(): string
    {
        return $this->config['name'] ?? Str::headline($this->slug());
    }

    /** Prefix for every env key this gateway reads. */
    protected function envPrefix(): string
    {
        return $this->config['env_prefix'] ?? strtoupper($this->slug());
    }

    protected function setting(string $key, ?string $default = null): ?string
    {
        return $this->env->get($this->envPrefix().'_'.$key, $default);
    }

    // ---------------------------------------------------------------------
    // Credentials
    // ---------------------------------------------------------------------

    public function isConfigured(): bool
    {
        foreach (['APP_SECRET', 'MERCHANT_CODE', 'MINI_APP_CODE'] as $key) {
            if (trim((string) $this->setting($key)) === '') {
                return false;
            }
        }

        // Either form of the key counts. Checked for presence only — whether it
        // actually parses is discovered when an order is signed, and failing
        // there gives a specific OpenSSL reason rather than the flat "this bank
        // is unavailable" that failing here would produce.
        return trim((string) $this->setting('PUBLIC_KEY')) !== ''
            || trim((string) $this->setting('PUBLIC_KEY_PATH')) !== '';
    }

    public function canVerifySettlement(): bool
    {
        return trim((string) $this->setting('BASE_URL')) !== ''
            && trim((string) $this->setting('ORDER_QUERY_PATH')) !== '';
    }

    protected function appSecret(): string
    {
        $secret = $this->setting('APP_SECRET');

        if (! $secret) {
            throw new \RuntimeException(
                $this->displayName().' app secret is not configured.'
            );
        }

        return $secret;
    }

    /**
     * The RSA public key issued at onboarding.
     *
     * PREFERS A FILE, AND THAT IS THE POINT.
     *
     * A PEM block is multi-line and a .env value is not: dotenv reads line by
     * line, so pasting a real key straight into .env captures the first line
     * and parses the remaining ten as malformed entries. That fails at boot if
     * you are lucky and truncates the key silently if you are not. Every one
     * of these integrations gets it wrong once.
     *
     * So {PREFIX}_PUBLIC_KEY_PATH points at a .pem file and the key is pasted
     * into it exactly as the bank sent it. That is also how this codebase
     * already handles the Firebase service account, and it keeps signing
     * material out of .env.example, which is committed.
     *
     * {PREFIX}_PUBLIC_KEY still works for a single-line value with literal
     * backslash-n escapes, because that is what the admin Settings screen
     * writes. Whatever the source, the body is stripped of ALL whitespace and
     * the PEM is rebuilt: a key pasted with spaces at the wrap points is
     * common and OpenSSL will not read it otherwise.
     */
    protected function publicKey(): string
    {
        $key = '';

        $path = trim((string) $this->setting('PUBLIC_KEY_PATH'));

        if ($path !== '') {
            $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $path)
                ? $path
                : base_path($path);

            if (! is_readable($resolved)) {
                throw new \RuntimeException(
                    $this->displayName().' public key file is not readable: '.$resolved
                );
            }

            $key = (string) file_get_contents($resolved);
        }

        if (trim($key) === '') {
            $key = (string) $this->setting('PUBLIC_KEY');
        }

        $key = trim($key);

        if ($key === '') {
            throw new \RuntimeException(
                $this->displayName().' public key is not configured.'
            );
        }

        $key = str_replace('\\n', "\n", $key);

        // Strip the armour if present, then everything that is not base64.
        // Rebuilding unconditionally is what makes a key pasted with spaces at
        // the wrap points work, and it costs nothing on a well-formed one.
        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----/', '', $key);
        $body = preg_replace('/[^A-Za-z0-9+\/=]/', '', (string) $body);

        if ($body === '' || $body === null) {
            throw new \RuntimeException(
                $this->displayName().' public key contains no key data.'
            );
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split($body, 64, "\n")
            .'-----END PUBLIC KEY-----';
    }

    public function stage(): string
    {
        return $this->setting('STAGE', 'uat') ?: 'uat';
    }

    public function miniAppCode(): string
    {
        return (string) $this->setting('MINI_APP_CODE');
    }

    public function merchantCode(): string
    {
        return (string) $this->setting('MERCHANT_CODE');
    }

    // ---------------------------------------------------------------------
    // Signing
    // ---------------------------------------------------------------------

    /**
     * RSA-encrypt the signed subset of the request.
     *
     * WHY A SUBSET, AND WHY IT IS CONFIGURABLE
     *
     * The vendor sample calls `pickKeys(body, keysToPick)` before encrypting
     * and never says what `keysToPick` contains. It cannot be the whole
     * request: RSA encrypts at most (modulus − padding) bytes in one
     * operation, which for a 2048-bit key under OAEP is 214. `biz_content`
     * alone is larger. So a subset is forced by the maths, and only the bank
     * can confirm which one.
     *
     * {PREFIX}_SIGN_KEYS therefore holds it, dot notation, comma separated.
     * The default is the smallest set that identifies the transaction — order
     * id, amount, merchant, plus the nonce and timestamp that stop it being
     * replayed.
     *
     * Oversized input throws rather than being chunked. Chunked RSA produces
     * ciphertext a single-shot decrypt cannot read, and that failure would
     * surface as an unexplained rejected payment rather than as the
     * configuration mistake it is.
     */
    protected function encryptPayload(array $request): string
    {
        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->setting('SIGN_KEYS', $this->defaultSignKeys()))
        )));

        $picked = [];
        foreach ($keys as $key) {
            $value = data_get($request, $key);
            if ($value !== null) {
                data_set($picked, $key, $value);
            }
        }

        $plain = json_encode($picked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // OAEP by default, matching Node's crypto.publicEncrypt, which is what
        // the vendor samples use. Some deployments are on PKCS#1 v1.5;
        // {PREFIX}_RSA_PADDING=pkcs1 switches it without a code change.
        $padding = strtolower((string) $this->setting('RSA_PADDING', 'oaep')) === 'pkcs1'
            ? OPENSSL_PKCS1_PADDING
            : OPENSSL_PKCS1_OAEP_PADDING;

        $encrypted = '';
        $ok = openssl_public_encrypt($plain, $encrypted, $this->publicKey(), $padding);

        if (! $ok) {
            $reason = openssl_error_string() ?: 'unknown OpenSSL error';

            Log::error('Payment payload encryption failed', [
                'gateway' => $this->slug(),
                'reason' => $reason,
                'signed_bytes' => strlen((string) $plain),
                'signed_keys' => $keys,
            ]);

            throw new \RuntimeException(
                'Could not sign the payment order ('.$reason.'). '
                .'If this says "data too large for key size", narrow '
                .$this->envPrefix().'_SIGN_KEYS.'
            );
        }

        return base64_encode($encrypted);
    }

    /** Fields signed into `sign` when the bank has not told us otherwise. */
    protected function defaultSignKeys(): string
    {
        return 'biz_content.merch_order_id,biz_content.total_amount,biz_content.merch_code,timestamp,nonce_str';
    }

    /**
     * HMAC-SHA256 over the whole request, including `stage`.
     *
     * KEY ORDERING IS LOAD-BEARING. The bank hashes the JSON text, not a
     * canonicalised object, so the bytes hashed must be the bytes sent. PHP
     * preserves array insertion order through json_encode, and every request
     * here is built in the documented order (timestamp, nonce_str, method,
     * version, biz_content, sign, stage). Do not reorder or sort these arrays.
     *
     * JSON_UNESCAPED_SLASHES matters for the same reason: PHP escapes "/" as
     * "\/" and Node does not, so without it any value containing a slash would
     * hash differently on the two sides.
     */
    protected function createHmac(array $request): string
    {
        return hash_hmac(
            'sha256',
            (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->appSecret()
        );
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    public function createOrder(string $reference, float $amount, string $narration): array
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
            'merch_order_id' => $reference,
            'title' => $this->narration($narration),
            // Two decimal places as a string. The column is decimal(12,2) and
            // money must not pass through a binary float on the way out.
            'total_amount' => number_format($amount, 2, '.', ''),
            'trans_currency' => config('payments.currency', 'ETB'),
            'timeout_express' => $this->setting('TIMEOUT_EXPRESS', '120m'),
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
     * Narration text, kept to a conservative character set.
     *
     * Not a documented restriction on any of these banks — it is precautionary.
     * The field is a statement narration, it carries names members typed in
     * themselves (including Amharic, which will not survive an ASCII-only
     * rail), and the previous gateway rejected entire transactions over
     * exactly this. Losing a cosmetic label always beats losing the payment.
     */
    protected function narration(?string $value, string $fallback = 'Equb contribution payment', int $limit = 50): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_. ]+/', ' ', (string) $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean), ' .');

        if ($clean === '') {
            return $fallback;
        }

        return rtrim(mb_substr($clean, 0, $limit), ' .');
    }

    /**
     * Credentials the client presents with the order.
     *
     * RAISE THIS WITH EVERY BANK BEFORE GOING TO PRODUCTION.
     *
     * The vendor samples pass the app secret as `xAPiKey` from inside client
     * JavaScript — the same secret that signs `confirmpayload`. Anyone with
     * devtools can then read it, which undermines signing the order on the
     * server at all.
     *
     * It is implemented because it is what the integration packs specify and
     * payments do not work without it, not because it is sound. The right fix
     * is a short-lived, order-scoped token; ask each bank whether one exists.
     * Until then treat {PREFIX}_APP_SECRET as public, and note that the real
     * protection against a tampered amount is server-side: the controller
     * reads what is owed from the membership and never from the request.
     */
    public function authPayload(?string $sessionToken = null): array
    {
        return [
            'xAPiKey' => $this->appSecret(),
            'xAccessToken' => $sessionToken,
        ];
    }

    // ---------------------------------------------------------------------
    // Settlement
    // ---------------------------------------------------------------------

    public function verifyPayment(string $reference): array
    {
        if (! $this->canVerifySettlement()) {
            Log::warning('Settlement verification skipped: order query endpoint not configured', [
                'gateway' => $this->slug(),
                'reference' => $reference,
            ]);

            return [
                'success' => false,
                'message' => $this->displayName().' order verification endpoint is not configured.',
                'unconfigured' => true,
            ];
        }

        try {
            $request = [
                'timestamp' => (string) now()->timestamp,
                'nonce_str' => strtoupper(Str::random(32)),
                'method' => $this->queryMethod(),
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
            ])->timeout(30)->post($this->endpoint('ORDER_QUERY_PATH'), $request);

            $data = $response->json();

            $status = strtoupper((string) (
                data_get($data, 'biz_content.trade_status')
                ?? data_get($data, 'trade_status')
                ?? data_get($data, 'status')
                ?? ''
            ));

            // An HTTP 200 on its own proves nothing — the body has to say the
            // trade succeeded. Several spellings are accepted because these
            // banks are not consistent with each other, but silence is not.
            $settled = in_array($status, $this->settledStatuses(), true);

            if ($response->successful() && $settled) {
                return ['success' => true, 'data' => $data, 'message' => 'Payment verified successfully'];
            }

            Log::info('Verification did not confirm settlement', [
                'gateway' => $this->slug(),
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
            Log::error('Payment verification errored', [
                'gateway' => $this->slug(),
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** The bank's method name for an order-status query. */
    protected function queryMethod(): string
    {
        return 'payment.queryorder';
    }

    /** @return array<int, string> */
    protected function settledStatuses(): array
    {
        return ['SUCCESS', 'SUCCEEDED', 'PAID', 'COMPLETED', 'TRADE_SUCCESS'];
    }

    protected function endpoint(string $pathKey): string
    {
        return rtrim((string) $this->setting('BASE_URL'), '/')
            .'/'.ltrim((string) $this->setting($pathKey), '/');
    }

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

    public function signatureHeaders(): array
    {
        return $this->config['signature_headers'] ?? ['x-'.$this->slug().'-signature'];
    }

    public function extractReference(array $payload): ?string
    {
        $reference = $payload['merch_order_id']
            ?? data_get($payload, 'biz_content.merch_order_id')
            ?? data_get($payload, 'data.merch_order_id')
            ?? null;

        return $reference !== null ? (string) $reference : null;
    }

    // ---------------------------------------------------------------------
    // Customer identity
    // ---------------------------------------------------------------------

    public function exchangeCustomerIdentifier(string $identifier): array
    {
        if (trim((string) $this->setting('BASE_URL')) === ''
            || trim((string) $this->setting('TOKEN_PATH')) === '') {
            return [
                'success' => false,
                'message' => $this->displayName().' token endpoint is not configured.',
                'unconfigured' => true,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->appSecret(),
            ])->timeout(30)->post($this->endpoint('TOKEN_PATH'), [
                'appid' => $this->miniAppCode(),
                'fabric_app_id' => $this->setting('FABRIC_APP_ID'),
                'merch_code' => $this->merchantCode(),
                'stage' => $this->stage(),
                'customeridentifier' => $identifier,
            ]);

            $data = $response->json();

            if (! $response->successful()) {
                Log::warning('Customer identifier exchange failed', [
                    'gateway' => $this->slug(),
                    'body' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => data_get($data, 'message') ?? 'Could not verify the session.',
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
            Log::error('Customer identifier exchange errored', [
                'gateway' => $this->slug(),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------------
    // Client
    // ---------------------------------------------------------------------

    public function clientConfig(): array
    {
        $client = $this->config['client'] ?? [];

        return [
            'slug' => $this->slug(),
            'name' => $this->displayName(),
            'kind' => $client['kind'] ?? 'superapp',
            // The global object the client talks to. Not a secret — it is a
            // property name the host app itself injects into the page.
            'bridge' => $client['bridge'] ?? null,
            // Needed by the client to identify itself when asking the host app
            // who the customer is. Public by design.
            'app_code' => $this->miniAppCode(),
            'stage' => $this->stage(),
            'supports_identity' => trim((string) $this->setting('TOKEN_PATH')) !== '',
        ];
    }
}

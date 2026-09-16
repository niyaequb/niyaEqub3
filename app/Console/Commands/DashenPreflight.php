<?php

namespace App\Console\Commands;

use App\Services\EnvService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;

/**
 * Check everything about a Dashen payment that can be checked WITHOUT the bank.
 *
 * WHY THIS EXISTS
 *
 * Getting this integration working took six weeks, and almost all of it was
 * spent on things that were verifiable from this side but nobody had verified:
 *
 *   * a merchant code with two digits transposed, sitting in the database
 *     while the correct one sat in .env, which is not read once config is
 *     cached
 *   * an access token that was null on every order ever sent
 *   * a `sign` field encrypting five fields where the bank wanted one
 *
 * Every one of those produced a bank error that named no field. So this command
 * asserts the things we CAN assert, against the credentials sheet, before
 * anyone is asked to walk to a phone and type a PIN.
 *
 * WHAT IT CANNOT TELL YOU
 *
 * Whether Dashen will accept the order. The plaintext inside `sign` is
 * encrypted with their public key and only they can open it; settlement
 * happens in their core system. A clean run here means "nothing we control is
 * wrong", which is worth a great deal and is not the same as "it works".
 *
 *     php artisan dashen:preflight
 *     php artisan dashen:preflight --identifier=367018154258487
 */
class DashenPreflight extends Command
{
    protected $signature = 'dashen:preflight
                            {--identifier= : Mint the token for this customer instead of the configured one}
                            {--gateway=dashen : Gateway slug to check}';

    protected $description = 'Verify the Dashen payment configuration and sign a throwaway order';

    /**
     * Values from the Dashen "Mini App Credentials" sheet for Niya Umrah Equb,
     * plus the two answers they gave in writing on 15-16 Sep 2026.
     *
     * Hardcoded on purpose. A check that reads the expected value from the same
     * place as the actual value cannot fail, which is how the transposed
     * merchant code survived every review it was given.
     */
    private const EXPECTED = [
        'MERCHANT_CODE' => '385141159275721',
        'MINI_APP_CODE' => '138437',
        'MERCHANT_ID' => '6a8bea061fc8d19411db02ee',
        'STAGE' => 'uat',
        'SIGN_KEYS' => 'timestamp',
        'RSA_PADDING' => 'oaep',
        'BASE_URL' => 'https://uatapp.dashenbanksc.com',
    ];

    /** Fingerprint of the 4096-bit public key on the credentials sheet. */
    private const KEY_B64_LENGTH = 736;
    private const KEY_SHA256 = '0f2363f5ba1c30b724a1bd721198eead3748051f05341121aa359bf94dd16e69';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $slug = (string) $this->option('gateway');
        $prefix = strtoupper($slug);

        $this->newLine();
        $this->line('  <fg=white;options=bold>Dashen preflight</> — '.now()->toDateTimeString());

        $gateway = app(PaymentGatewayManager::class)->tryGet($slug);

        if (! $gateway) {
            $this->section('Gateway');
            $this->bad('resolve', $slug.' is not registered, or its credentials are incomplete');
            $this->verdict();

            return self::FAILURE;
        }

        $env = app(EnvService::class);

        $this->checkCredentials($env, $prefix);
        $this->checkPublicKey($gateway);
        $token = $this->checkFabricToken($gateway);
        $this->checkOrder($gateway, $env, $prefix, $token);
        $this->checkProductionReadiness($env, $prefix);

        return $this->verdict();
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    private function checkCredentials(EnvService $env, string $prefix): void
    {
        $this->section('Credentials');

        foreach (self::EXPECTED as $key => $expected) {
            $actual = trim((string) $env->get($prefix.'_'.$key));

            // SIGN_KEYS and RSA_PADDING legitimately fall through to the
            // gateway's own defaults, which are the same values.
            if ($actual === '' && in_array($key, ['SIGN_KEYS', 'RSA_PADDING'], true)) {
                $this->ok($key, 'unset — gateway default applies ('.$expected.')');

                continue;
            }

            $actual === $expected
                ? $this->ok($key, $actual)
                : $this->bad($key, 'is '.($actual === '' ? '(empty)' : $actual).', expected '.$expected);
        }

        $secret = trim((string) $env->get($prefix.'_APP_SECRET'));

        // Never printed. It is the HMAC key, and this output gets pasted into
        // chat threads.
        strlen($secret) === 48
            ? $this->ok('APP_SECRET', '48 chars, ends '.substr($secret, -6))
            : $this->bad('APP_SECRET', strlen($secret).' chars, expected 48');

        $path = trim((string) $env->get($prefix.'_FABRIC_TOKEN_PATH'))
            ?: trim((string) $env->get($prefix.'_TOKEN_PATH'));

        $path !== ''
            ? $this->ok('FABRIC_TOKEN_PATH', $path)
            : $this->bad('FABRIC_TOKEN_PATH', 'not set — every order is refused as "Incomplete request"');
    }

    // -----------------------------------------------------------------
    // Public key
    // -----------------------------------------------------------------

    private function checkPublicKey(object $gateway): void
    {
        $this->section('Public key');

        try {
            $method = new \ReflectionMethod($gateway, 'publicKey');
            $method->setAccessible(true);
            $pem = (string) $method->invoke($gateway);
        } catch (\Throwable $e) {
            $this->bad('resolve', $e->getMessage());

            return;
        }

        $body = (string) preg_replace(
            '/[^A-Za-z0-9+\/=]/',
            '',
            (string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----/', '', $pem)
        );

        $resource = @openssl_pkey_get_public($pem);

        if (! $resource) {
            $this->bad('parse', openssl_error_string() ?: 'OpenSSL refused the key');

            return;
        }

        $bits = openssl_pkey_get_details($resource)['bits'] ?? 0;

        $bits === 4096
            ? $this->ok('size', '4096 bits')
            : $this->bad('size', $bits.' bits, expected 4096');

        // Length and hash together, because a key can be a valid RSA key and
        // still be the wrong one — which is exactly what a stale reissued key
        // would look like.
        strlen($body) === self::KEY_B64_LENGTH && hash('sha256', $body) === self::KEY_SHA256
            ? $this->ok('identity', 'matches the credentials sheet')
            : $this->bad('identity', 'does NOT match the sheet (len '.strlen($body)
                .', sha256 '.substr(hash('sha256', $body), 0, 16).'…)');
    }

    // -----------------------------------------------------------------
    // Fabric token
    // -----------------------------------------------------------------

    private function checkFabricToken(object $gateway): ?string
    {
        $this->section('Fabric token');

        $identifier = $this->option('identifier');

        try {
            $auth = $gateway->authPayload($identifier ?: null);
        } catch (\Throwable $e) {
            $this->bad('mint', $e->getMessage());

            return null;
        }

        $token = trim((string) ($auth['xAccessToken'] ?? ''));

        if ($token === '') {
            $this->bad('xAccessToken', 'empty — the bank answers "Incomplete request"');

            return null;
        }

        $this->ok('xAccessToken', strlen($token).' chars');

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            $this->warn2('shape', 'not a three-part JWT; cannot read the customer or expiry');

            return $token;
        }

        $claims = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), false),
            true
        );

        if (! is_array($claims)) {
            $this->warn2('claims', 'could not be decoded');

            return $token;
        }

        $name = data_get($claims, 'customer.name', '(unnamed)');
        $code = data_get($claims, 'customer.userCode', '(none)');
        $this->ok('customer', $name.' — '.$code);

        $exp = (int) data_get($claims, 'exp', 0);

        if ($exp > 0) {
            $seconds = $exp - time();

            $seconds > 60
                ? $this->ok('expires', 'in '.round($seconds / 60).' min')
                : $this->bad('expires', $seconds.'s — too short to complete a payment');
        }

        return $token;
    }

    // -----------------------------------------------------------------
    // The order itself
    // -----------------------------------------------------------------

    private function checkOrder(object $gateway, EnvService $env, string $prefix, ?string $token): void
    {
        $this->section('Signed order');

        try {
            $order = $gateway->createOrder('EQUB-PREFLIGHT1', 1889.00, 'Preflight check');
        } catch (\Throwable $e) {
            $this->bad('sign', $e->getMessage());

            return;
        }

        $expectedKeys = ['timestamp', 'nonce_str', 'method', 'version', 'biz_content', 'sign', 'stage', 'confirmpayload'];

        array_keys($order) === $expectedKeys
            ? $this->ok('field order', implode(', ', $expectedKeys))
            : $this->bad('field order', 'is '.implode(', ', array_keys($order)).' — the HMAC is over this exact order');

        $merch = (string) data_get($order, 'biz_content.merch_code');
        $merch === self::EXPECTED['MERCHANT_CODE']
            ? $this->ok('merch_code', $merch)
            : $this->bad('merch_code', $merch.', expected '.self::EXPECTED['MERCHANT_CODE']);

        $payee = (string) data_get($order, 'biz_content.payee_identifier');
        $payee === $merch
            ? $this->ok('payee_identifier', 'matches merch_code')
            : $this->bad('payee_identifier', $payee.' does not match merch_code');

        $amount = (string) data_get($order, 'biz_content.total_amount');
        preg_match('/^\d+\.\d{2}$/', $amount) === 1
            ? $this->ok('total_amount', '"'.$amount.'" (two-decimal string)')
            : $this->bad('total_amount', '"'.$amount.'" is not a two-decimal string');

        strlen((string) $order['timestamp']) === 10
            ? $this->ok('timestamp', $order['timestamp'].' (seconds)')
            : $this->bad('timestamp', $order['timestamp'].' — expected 10-digit seconds, not milliseconds');

        // --- what actually goes inside sign ---------------------------
        //
        // Reconstructed here rather than read from the gateway, so this says
        // what the BANK receives and not what the code intended to send.
        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($env->get($prefix.'_SIGN_KEYS') ?: 'timestamp'))
        )));

        $picked = [];
        foreach ($keys as $key) {
            $value = data_get($order, $key);
            if ($value !== null) {
                data_set($picked, $key, $value);
            }
        }

        $plain = (string) json_encode($picked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $keys === ['timestamp']
            ? $this->ok('sign plaintext', $plain)
            : $this->bad('sign plaintext', $plain.' — Dashen want the timestamp ONLY');

        $signBytes = strlen((string) base64_decode((string) $order['sign'], true));
        $signBytes === 512
            ? $this->ok('sign', '512 bytes (one 4096-bit RSA block)')
            : $this->bad('sign', $signBytes.' bytes, expected 512');

        // --- confirmpayload, recomputed independently -----------------
        //
        // Using the raw secret from settings rather than the gateway's own
        // createHmac(), so a bug in that method cannot verify itself.
        $withoutHmac = $order;
        unset($withoutHmac['confirmpayload']);

        $recomputed = hash_hmac(
            'sha256',
            (string) json_encode($withoutHmac, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string) $env->get($prefix.'_APP_SECRET')
        );

        hash_equals($recomputed, (string) $order['confirmpayload'])
            ? $this->ok('confirmpayload', 'independently recomputed and matches')
            : $this->bad('confirmpayload', 'does NOT match an independent recomputation');

        if ($token !== null) {
            $this->ok('ready', 'this order would go to the bank with a live token');
        }
    }

    // -----------------------------------------------------------------
    // Production readiness
    // -----------------------------------------------------------------

    private function checkProductionReadiness(EnvService $env, string $prefix): void
    {
        $this->section('Before production');

        $hardcoded = trim((string) $env->get($prefix.'_CUSTOMER_IDENTIFIER'));

        $hardcoded === ''
            ? $this->ok('CUSTOMER_IDENTIFIER', 'unset — the client supplies the live customer')
            : $this->warn2(
                'CUSTOMER_IDENTIFIER',
                'set to '.$hardcoded.'. Correct for UAT. In production this stamps '
                .'one person\'s identity on every member\'s order.'
            );

        $query = trim((string) $env->get($prefix.'_ORDER_QUERY_PATH'));

        $query === ''
            ? $this->warn2('ORDER_QUERY_PATH', 'unset — settlement is manual; an operator marks contributions paid')
            : $this->ok('ORDER_QUERY_PATH', $query.' — settlement credits itself');

        if (filter_var(env('APP_DEBUG'), FILTER_VALIDATE_BOOL) && app()->environment('production')) {
            $this->warn2('APP_DEBUG', 'true in production — an error page would print these credentials');
        }
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <fg=white;options=bold>'.$title.'</>');
    }

    private function ok(string $label, string $detail): void
    {
        $this->line('  <fg=green>  OK</>  '.str_pad($label, 18).' <fg=gray>'.$detail.'</>');
    }

    private function bad(string $label, string $detail): void
    {
        $this->failures++;
        $this->line('  <fg=red>FAIL</>  '.str_pad($label, 18).' <fg=red>'.$detail.'</>');
    }

    private function warn2(string $label, string $detail): void
    {
        $this->warnings++;
        $this->line('  <fg=yellow>WARN</>  '.str_pad($label, 18).' <fg=yellow>'.$detail.'</>');
    }

    private function verdict(): int
    {
        $this->newLine();

        if ($this->failures > 0) {
            $this->line('  <fg=red;options=bold>'.$this->failures.' check(s) failed.</> Fix these before asking anyone to test.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=green;options=bold>Everything this side controls is correct.</>');

        if ($this->warnings > 0) {
            $this->line('  <fg=yellow>'.$this->warnings.' warning(s) above are fine for UAT and must be resolved before go-live.</>');
        }

        $this->line('  <fg=gray>Not checked: whether the bank accepts it. The plaintext inside `sign`</>');
        $this->line('  <fg=gray>is encrypted with their key, and settlement happens in their system.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

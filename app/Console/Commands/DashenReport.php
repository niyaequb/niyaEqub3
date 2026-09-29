<?php

namespace App\Console\Commands;

use App\Services\EnvService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Dashen troubleshooting report that is safe to send to the bank.
 *
 * WHY THIS EXISTS
 *
 * The bank asked for "every important log". The raw log is the wrong thing to
 * send: it carries this server's file paths and address, other members' data
 * and, on some lines, configuration values. This command collects what the
 * bank can actually use (what we send, what they answer, and when) and
 * removes everything else.
 *
 * WHAT IT DOES
 *
 *   1. Shows the saved Dashen settings and which of Dashen's credential sets
 *      each value belongs to, so a half-switched configuration is obvious.
 *   2. Asks the token endpoint for a token once per stage value and prints the
 *      exact request (without the secret), the answer and the time.
 *   3. Builds and signs a sample 1.00 ETB order WITHOUT sending it anywhere.
 *   4. Lists the recent Dashen lines from the application log, cleaned.
 *
 * It changes no settings and creates no payments.
 *
 *     php artisan dashen:report
 *     php artisan dashen:report --days=7 --offline
 */
class DashenReport extends Command
{
    protected $signature = 'dashen:report
                            {--days=3 : Days of log history to include}
                            {--max=40 : Most log lines to include}
                            {--offline : Do not contact the bank}';

    protected $description = 'Write a Dashen troubleshooting report that is safe to share with the bank';

    /**
     * Dashen's credential sets for Niya. Identifiers as issued; the app secret
     * and the public key only as SHA-256 fingerprints (of the secret string and
     * of the key's base64 body), so nothing here can sign anything.
     *
     * 'uat' and 'production' are the two blocks of Dashen's registry record
     * for mini app 190321, sent on 29 Sep 2026. 'old-uat' is the credential
     * sheet of 26 Aug 2026 for mini app 138437.
     */
    private const SETS = [
        'old-uat' => [
            'stage' => 'uat',
            'MINI_APP_CODE' => '138437',
            'MERCHANT_CODE' => '385141159275721',
            'MERCHANT_ID' => '6a8bea061fc8d19411db02ee',
            'MERCHANT_APP_ID' => '9932847442989661',
            'FABRIC_APP_ID' => '66ee2cd2-f7f1-d6f4-4d29-b1f5494e827a',
            'SHORT_CODE' => '998012',
            'secret' => '0454f2aa25c3f70ef386cae1dcf4624b2de928ad90973cf2accb1a9b2f147bc1',
            'key' => '0f2363f5ba1c30b724a1bd721198eead3748051f05341121aa359bf94dd16e69',
        ],
        'uat' => [
            'stage' => 'uat',
            'MINI_APP_CODE' => '190321',
            'MERCHANT_CODE' => '677260873559382',
            'MERCHANT_ID' => '6ab517406012f0b2b93ee8bb',
            'MERCHANT_APP_ID' => '1761314105440355',
            'FABRIC_APP_ID' => '79511694-8032-5b66-a42e-3b57a6f656c0',
            'SHORT_CODE' => '960964',
            'secret' => '86d3c923b3779f503133a778cb2704d6f3c51a77c0f7ab22563bdfc2b5c10208',
            'key' => 'bef1816536a2e40cd59f1aa03abb107e99586d32972f63864d89443493b3af15',
        ],
        'production' => [
            'stage' => 'production',
            'MINI_APP_CODE' => '190321',
            'MERCHANT_CODE' => '677260873559382',
            'MERCHANT_ID' => '6ab517406012f0b2b93ee8bb',
            'MERCHANT_APP_ID' => '3623914722144594',
            'FABRIC_APP_ID' => 'e287bbb3-0f1d-db1a-ec8f-9dbac94a24a7',
            'SHORT_CODE' => '263047',
            'secret' => '1c17dc5c7261a5ed08bc336584f586e840e0ec19aacebd44a8e8ac450b8475d2',
            'key' => '8189349f2e54f875bcec4e04b4adbe97696c26e4e378553ce29a08a1dec7e790',
        ],
    ];

    /** Field => how it is labelled in the report. */
    private const FIELDS = [
        'MINI_APP_CODE' => 'appcode',
        'MERCHANT_ID' => 'merchant_id',
        'MERCHANT_CODE' => 'merch_code',
        'MERCHANT_APP_ID' => 'merchant app id',
        'FABRIC_APP_ID' => 'fabric app id',
        'SHORT_CODE' => 'short code',
        'secret' => 'app secret',
        'key' => 'public key',
    ];

    /** Log messages worth sending. Every other log line is left out. */
    private const LOG_PATTERN = '/Fabric token|Could not sign an Equb payment order|Customer identifier exchange'
        .'|Mini-app identifier rejected|Payment payload encryption failed|public key file is not readable'
        .'|configured customer identifier|Could not mint a token|Payment verif|Bank reports this payment'
        .'|did not confirm settlement|Settlement verification skipped|settlement notification'
        .'|notification processing failed|Batch Equb payment could not be created'
        .'|Could not check an earlier attempt|Could not read the settlement time'
        .'|"gateway":"dashen"|"provider":"dashen"/i';

    /** @var array<int, string> */
    private array $report = [];

    /** @var array<int, string> */
    private array $findings = [];

    public function handle(EnvService $env): int
    {
        $get = fn (string $key): string => trim((string) $env->get('DASHEN_'.$key));
        $gateway = app(PaymentGatewayManager::class)->tryGet('dashen');

        $utc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $addis = $utc->setTimezone(new \DateTimeZone('Africa/Addis_Ababa'));

        $this->add(str_repeat('=', 66));
        $this->add(' DASHEN INTEGRATION REPORT - Niya Umrah Equb');
        $this->add(' Generated '.$utc->format('Y-m-d H:i:s').' UTC ('.$addis->format('H:i').' Addis Ababa)');
        $this->add(' Secrets, tokens, file paths, IP addresses and personal details are');
        $this->add(' removed. Nothing in this report can be used to sign a payment.');
        $this->add(str_repeat('=', 66));

        // -----------------------------------------------------------------
        // 1. Settings
        // -----------------------------------------------------------------

        $stage = $get('STAGE') ?: 'uat';
        $secret = $get('APP_SECRET');
        [$keyHash, $keyInfo] = $this->keyFingerprint($gateway);

        $saved = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $saved[$field] = match ($field) {
                'secret' => $secret === '' ? '' : hash('sha256', $secret),
                'key' => $keyHash,
                default => $get($field),
            };
        }

        $base = rtrim($get('BASE_URL'), '/');
        $path = $get('FABRIC_TOKEN_PATH') ?: $get('TOKEN_PATH');
        $url = ($base !== '' && $path !== '') ? $base.'/'.ltrim($path, '/') : '';

        $this->add('');
        $this->add('1. SETTINGS THIS SERVER USES   [Dashen credential set each value is from]');
        $this->row('stage', $stage);

        foreach (self::FIELDS as $field => $label) {
            $shown = match ($field) {
                'secret' => $secret === '' ? '' : strlen($secret).' characters (not shown)',
                'key' => $keyInfo,
                default => $saved[$field],
            };

            $this->row($label, $shown === '' ? '(empty)' : $shown, $this->tag($field, $saved[$field]));
        }

        $this->row('token endpoint', $url ?: '(not set)');
        $this->row('order query path', $get('ORDER_QUERY_PATH') !== '' ? 'set' : '(not set)');

        if ($gateway) {
            $client = $gateway->clientConfig();
            $this->row('mini app sends', 'appcode '.($client['app_code'] ?? '?').' to the SuperApp');
        }

        $this->checkSets($saved, $stage);

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($stage === 'production' && str_contains(strtolower($host), 'uat')) {
            $this->findings[] = 'Stage "production" is being sent to a UAT address ('.$host.').';
        }

        if ($get('CUSTOMER_IDENTIFIER') !== '' && $stage === 'production') {
            $this->findings[] = 'A fixed customer identifier (a UAT stand-in) is configured; it must be empty for production.';
        }

        // -----------------------------------------------------------------
        // 2. Token requests
        // -----------------------------------------------------------------

        $this->add('');
        $this->add('2. TOKEN REQUESTS'.($url !== '' ? '   POST '.$url : ''));

        if ($this->option('offline')) {
            $this->add('   Skipped (--offline).');
        } elseif ($url === '' || $secret === '' || $saved['MINI_APP_CODE'] === '' || $saved['MERCHANT_ID'] === '') {
            $this->add('   Skipped: base URL, token path, app secret, mini app code or merchant id is not set.');
        } else {
            $this->add('   Header x-api-key = the saved app secret (not shown). No customer identifier.');

            $results = [];

            foreach (array_values(array_unique([$stage, 'uat', 'production'])) as $i => $try) {
                $result = $this->tokenRequest($url, $secret, $try, $saved['MINI_APP_CODE'], $saved['MERCHANT_ID']);
                $results[$try] = $result;

                $this->add('');
                $this->add('   '.chr(65 + $i).'. stage "'.$try.'"'.($try === $stage ? '   (the saved stage)' : ''));
                $this->row('sent', $result['sent'], null, 6);
                $this->row('request', $result['request'], null, 6);

                if (isset($result['error'])) {
                    $this->row('answer', 'no answer: '.$result['error'], null, 6);

                    continue;
                }

                $this->row('answer', 'HTTP '.$result['status'].' after '.$result['ms'].' ms'
                    .($result['ok'] ? ' - token issued' : ''), null, 6);
                $this->row('body', $result['body'], null, 6);

                foreach ($result['headers'] as $name => $value) {
                    $this->row($name, $value, null, 6);
                }
            }

            $this->tokenFindings($results, $stage, $host, $saved);
        }

        // -----------------------------------------------------------------
        // 3. Sample order
        // -----------------------------------------------------------------

        $this->add('');
        $this->add('3. SAMPLE ORDER (built and signed here, NOT sent to anyone)');

        if (! $gateway) {
            $this->add('   Skipped: the dashen gateway is not registered.');
        } else {
            try {
                $order = $gateway->createOrder('EQUB-REPORT-'.$utc->format('ymdHis'), 1.00, 'Diagnostic order');

                $this->row('method', ($order['method'] ?? '?').', version '.($order['version'] ?? '?'));
                $this->row('stage', (string) ($order['stage'] ?? '?'));

                foreach ((array) ($order['biz_content'] ?? []) as $key => $value) {
                    $this->row((string) $key, is_scalar($value) ? (string) $value : (string) json_encode($value));
                }

                $this->row('sign', strlen((string) ($order['sign'] ?? '')).' characters (not shown)');
                $this->row('confirmpayload', strlen((string) ($order['confirmpayload'] ?? '')).' characters (not shown)');
            } catch (\Throwable $e) {
                $this->add('   Could not build an order: '.$this->clean($e->getMessage()));
            }
        }

        // -----------------------------------------------------------------
        // 4. Log lines
        // -----------------------------------------------------------------

        $days = max(1, (int) $this->option('days'));
        $max = max(1, (int) $this->option('max'));
        [$lines, $total] = $this->logLines($days, $max);

        $this->add('');
        $this->add('4. DASHEN LOG LINES, LAST '.$days.' DAY(S)   times as logged ('.config('app.timezone', 'UTC').')');

        if ($lines === []) {
            $this->add('   None found.');
        } else {
            if ($total > count($lines)) {
                $this->add('   Showing the newest '.count($lines).' of '.$total.'.');
            }

            foreach ($lines as $line) {
                $this->add('   '.$line);
            }
        }

        // -----------------------------------------------------------------
        // 5. Findings
        // -----------------------------------------------------------------

        $this->add('');
        $this->add('5. WHAT THIS SHOWS');

        foreach ($this->findings ?: ['Nothing wrong found in the settings or the token request.'] as $finding) {
            $this->add('   - '.$finding);
        }

        $this->add(str_repeat('=', 66));

        // Raw, because a bank error page can contain <tags> the console
        // formatter would otherwise try to interpret.
        foreach ($this->report as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $file = 'dashen-report-'.$utc->format('Ymd-His').'.txt';
        $written = @file_put_contents(storage_path('app/'.$file), implode(PHP_EOL, $this->report).PHP_EOL);

        $this->newLine();
        $this->line($written !== false
            ? 'Saved as storage/app/'.$file.'. Send the text between the ==== lines.'
            : 'Could not save a copy. Copy the text between the ==== lines.');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /**
     * SHA-256 of the base64 body of the key the gateway will actually sign
     * with, whichever source it comes from, plus a short description.
     *
     * @return array{0: string, 1: string}
     */
    private function keyFingerprint(?object $gateway): array
    {
        if (! $gateway) {
            return ['', '(gateway not registered)'];
        }

        try {
            // Protected on the gateway; reflection can call it without
            // setAccessible() since PHP 8.1.
            $pem = (string) (new \ReflectionMethod($gateway, 'publicKey'))->invoke($gateway);
        } catch (\Throwable $e) {
            return ['', '(unusable: '.$this->clean($e->getMessage()).')'];
        }

        $body = (string) preg_replace(
            '/[^A-Za-z0-9+\/=]/',
            '',
            (string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----/', '', $pem)
        );

        $resource = @openssl_pkey_get_public($pem);
        $bits = $resource ? (int) (openssl_pkey_get_details($resource)['bits'] ?? 0) : 0;

        return [
            hash('sha256', $body),
            $bits > 0 ? $bits.'-bit RSA, ends '.substr($body, -12) : '(OpenSSL cannot read it)',
        ];
    }

    /** Names of the Dashen sets a saved value belongs to. */
    private function setsFor(string $field, string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_keys(array_filter(
            self::SETS,
            fn (array $set): bool => ($set[$field] ?? null) === $value
        ));
    }

    private function tag(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $sets = $this->setsFor($field, $value);

        return $sets === [] ? '[no Dashen set]' : '['.implode(', ', $sets).']';
    }

    /**
     * Do all saved values come from one set, and does the stage fit that set?
     *
     * @param  array<string, string>  $saved
     */
    private function checkSets(array $saved, string $stage): void
    {
        $candidates = array_keys(self::SETS);
        $unknown = [];
        $empty = [];

        foreach (self::FIELDS as $field => $label) {
            if ($saved[$field] === '') {
                $empty[] = $label;

                continue;
            }

            $sets = $this->setsFor($field, $saved[$field]);

            if ($sets === []) {
                $unknown[] = $label;

                continue;
            }

            $candidates = array_values(array_intersect($candidates, $sets));
        }

        if ($empty !== []) {
            $this->findings[] = 'Not set: '.implode(', ', $empty).'.';
        }

        if ($unknown !== []) {
            $this->findings[] = 'These values match none of Dashen\'s credential sets: '.implode(', ', $unknown).'.';
        }

        if ($candidates === []) {
            $this->findings[] = 'MIXED CREDENTIALS: the saved values come from different Dashen sets (see the tags in section 1).';

            return;
        }

        if ($unknown !== [] || count($candidates) !== 1) {
            return;
        }

        $set = $candidates[0];
        $this->findings[] = 'Every saved value is from the "'.$set.'" set.';

        if (self::SETS[$set]['stage'] !== $stage) {
            $this->findings[] = 'STAGE MISMATCH: stage is "'.$stage.'" but the saved credentials are the "'
                .$set.'" set, which belongs to stage "'.self::SETS[$set]['stage'].'".';
        }
    }

    // ---------------------------------------------------------------------
    // Token requests
    // ---------------------------------------------------------------------

    /**
     * The same request FabricGateway::fabricToken() sends, minus the customer.
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(string $url, string $secret, string $stage, string $appcode, string $merchantId): array
    {
        $body = ['stage' => $stage, 'appcode' => $appcode, 'merchant_id' => $merchantId];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $result = [
            'sent' => $now->format('Y-m-d H:i:s').' UTC ('
                .$now->setTimezone(new \DateTimeZone('Africa/Addis_Ababa'))->format('H:i:s').' Addis Ababa)',
            'request' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $started = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $secret,
            ])->timeout(20)->post($url, $body);
        } catch (\Throwable $e) {
            return $result + ['error' => $this->clean(str_replace($secret, '[hidden]', $e->getMessage()))];
        }

        $data = $response->json();
        $token = '';

        foreach (['token', 'access_token', 'accessToken', 'data.token', 'data.access_token',
            'biz_content.token', 'biz_content.access_token'] as $candidate) {
            $value = is_array($data) ? data_get($data, $candidate) : null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '') {
                $token = $value;
                break;
            }
        }

        $headers = [];

        foreach ($response->headers() as $name => $values) {
            if (preg_match('/^(date|x-request-id|x-correlation-id|x-trace-id|traceparent|x-amzn-trace-id|request-id)$/i', $name)) {
                $headers[strtolower($name)] = $this->clean(implode(', ', (array) $values));
            }
        }

        $body = $this->clean(str_replace($secret, '[hidden]', $response->body()));
        $message = is_array($data) ? data_get($data, 'message') : null;

        return $result + [
            'status' => $response->status(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'ok' => $response->successful() && $token !== '',
            'message' => is_scalar($message) ? (string) $message : '',
            'body' => mb_strimwidth($body === '' ? '(empty)' : $body, 0, 500, '...'),
            'headers' => $headers,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, string>  $saved
     */
    private function tokenFindings(array $results, string $stage, string $host, array $saved): void
    {
        $working = array_keys(array_filter($results, fn (array $r): bool => $r['ok'] ?? false));
        $answered = array_filter($results, fn (array $r): bool => ! isset($r['error']));

        if ($answered === []) {
            $this->findings[] = 'The token endpoint could not be reached from this server.';

            return;
        }

        if ($working === []) {
            $messages = array_unique(array_map(
                fn (array $r): string => 'HTTP '.$r['status'].' '.($r['message'] !== ''
                    ? '"'.$r['message'].'"'
                    : mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', strip_tags($r['body']))), 0, 120, '...')),
                $answered
            ));

            $this->findings[] = 'The server at '.$host.' refused every stage for appcode '.$saved['MINI_APP_CODE']
                .' with merchant_id '.$saved['MERCHANT_ID'].': '.implode('; ', $messages).'.';

            return;
        }

        if (in_array($stage, $working, true)) {
            $this->findings[] = 'The saved stage "'.$stage.'" gets a token from '.$host.'.';

            return;
        }

        $this->findings[] = 'The saved stage "'.$stage.'" is refused, but stage "'.implode('", "', $working)
            .'" gets a token from '.$host.' with the same credentials.';
    }

    // ---------------------------------------------------------------------
    // Logs
    // ---------------------------------------------------------------------

    /**
     * Recent Dashen lines from every log file, oldest first, cleaned.
     *
     * @return array{0: array<int, string>, 1: int}
     */
    private function logLines(int $days, int $max): array
    {
        $since = time() - $days * 86400;
        $files = array_filter(
            glob(storage_path('logs').'/*.log') ?: [],
            fn (string $file): bool => (int) @filemtime($file) >= $since
        );
        sort($files);

        $lines = [];

        foreach ($files as $file) {
            foreach ($this->tail($file, 30 * 1024 * 1024) as $line) {
                if (! preg_match('/^\[(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})[^\]]*\]\s+[\w-]+\.(\w+):\s?(.*)$/', $line, $m)) {
                    continue;
                }

                $when = strtotime($m[1].' '.$m[2]);

                if ($when === false || $when < $since || ! preg_match(self::LOG_PATTERN, $m[4])) {
                    continue;
                }

                $text = (string) preg_replace('/\s+\[\]\s*$/', '', $m[4]);
                $lines[] = [$when, '['.$m[1].' '.$m[2].'] '.$m[3].' '.mb_strimwidth($this->clean($text), 0, 600, '...')];
            }
        }

        usort($lines, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [array_column(array_slice($lines, -$max), 1), count($lines)];
    }

    /** The last $bytes of a file, line by line. */
    private function tail(string $file, int $bytes): \Generator
    {
        $handle = @fopen($file, 'rb');

        if (! $handle) {
            return;
        }

        $size = (int) @filesize($file);

        if ($size > $bytes) {
            fseek($handle, $size - $bytes);
            fgets($handle);
        }

        while (($line = fgets($handle)) !== false) {
            yield rtrim($line, "\r\n");
        }

        fclose($handle);
    }

    /**
     * Remove anything that should not leave this server: keys, tokens,
     * secrets, file paths, IP addresses, e-mail addresses, phone numbers and
     * personal fields. Errs on the side of hiding too much.
     */
    private function clean(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s', '[key]', $text);

        // Values of sensitive or personal JSON fields.
        $text = (string) preg_replace_callback(
            '/"([\w-]*(?:token|secret|password|authorization|api[-_]?key|sign|confirmpayload|name|phone|msisdn|mobile|email|account|payer|customer)[\w-]*)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i',
            function (array $m): string {
                if (preg_match('/merchant|payee|gateway|provider/i', $m[1])) {
                    return $m[0];
                }

                // Names and e-mail addresses go completely; numbers keep their
                // last three digits so a line can still be matched to a person
                // by someone who already knows who it is.
                $hide = preg_match('/token|secret|password|authorization|key|sign|confirmpayload|name|email/i', $m[1])
                    || mb_strlen($m[2]) <= 3;

                return '"'.$m[1].'":"'.($hide ? '[hidden]' : '...'.mb_substr($m[2], -3)).'"';
            },
            $text
        );

        $rules = [
            '~(?<![\w:/.\\\\])\\\\?/(?:var|home|srv|opt|usr|etc|tmp|root|mnt|data)(?:\\\\?/[^\s"\',)\]\\\\]+)+~' => '[path]',
            '/eyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*/' => '[token]',
            '/\b[0-9a-fA-F]{40,}\b/' => '[hidden]',
            '/[A-Za-z0-9+\/_-]{40,}={0,2}/' => '[hidden]',
            '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/' => '[email]',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
            '/\b(?:[0-9a-fA-F]{1,4}:){4,7}[0-9a-fA-F]{1,4}\b/' => '[ip]',
            '/(?<![\d+])(?:\+?251|0)?[79]\d{8}(?!\d)/' => '[phone]',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $text);
    }

    // ---------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------

    private function add(string $line): void
    {
        $this->report[] = $line;
    }

    private function row(string $label, string $value, ?string $tag = null, int $indent = 3): void
    {
        $this->add(rtrim(str_repeat(' ', $indent).str_pad($label, 18).' '.$value.($tag ? '   '.$tag : '')));
    }
}

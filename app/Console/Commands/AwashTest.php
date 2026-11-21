<?php

namespace App\Console\Commands;

use App\Services\Payments\Gateways\AwashGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;

/**
 * Talk to Awash Bank from the server, without the apps.
 *
 *   php artisan awash:test                          settings, signature, VPN reachability
 *   php artisan awash:test --phone=0911223344 --amount=1.00
 *                                                   send a real debit request (UAT)
 *   php artisan awash:test --status=AWASH-TEST-...  ask the status of a reference
 *
 * A test request is sent with an AWASH-TEST- reference, which no contribution
 * carries, so nothing in Equb is ever credited by it.
 */
class AwashTest extends Command
{
    protected $signature = 'awash:test {--phone=} {--amount=1.00} {--status=}';

    protected $description = 'Check the Awash Bank merchant debit integration (settings, request, status)';

    public function handle(PaymentGatewayManager $gateways): int
    {
        $g = $gateways->tryGet('awash');

        if (! $g instanceof AwashGateway) {
            $this->error('The awash gateway is not registered (config/payments.php).');

            return self::FAILURE;
        }

        if (! $g->isConfigured()) {
            $this->error('Awash is registered but not configured. Set AWASH_BASE_URL, AWASH_MERCHANT_CODE, AWASH_TILL_NUMBER, AWASH_PASSWORD.');

            return self::FAILURE;
        }

        $this->info('1. Settings: OK');
        $this->line('   Base URL:     '.$g->setting('BASE_URL'));
        $this->line('   Resolve IP:   '.($g->setting('RESOLVE_IP') ?: '(DNS)'));
        $this->line('   Source IP:    '.($g->setting('SOURCE_IP') ?: '(kernel default)'));
        $this->line('   Merchant:     '.$g->setting('MERCHANT_CODE').' / till '.$g->setting('TILL_NUMBER'));
        $this->line('   Callback URL: '.$g->callbackUrl());
        $this->line('   Shown in apps: '.($gateways->isListed('awash') ? 'yes' : 'no (AWASH_SHOW_IN_APPS=false)'));

        $auth = $g->authorization();
        $this->info('2. Signature: requestId '.$auth['requestId'].' -> '.substr($auth['requestSignature'], 0, 16).'...');

        $host = (string) parse_url((string) $g->setting('BASE_URL'), PHP_URL_HOST);
        $ip = $g->setting('RESOLVE_IP') ?: gethostbyname($host);
        $port = (int) (parse_url((string) $g->setting('BASE_URL'), PHP_URL_PORT) ?: 443);
        $socket = @fsockopen($ip, $port, $errno, $errstr, 5);
        if ($socket) {
            fclose($socket);
            $this->info("3. Network: {$ip}:{$port} reachable");
        } else {
            $this->error("3. Network: {$ip}:{$port} NOT reachable ({$errstr}). Is the Awash VPN up?  sudo ipsec status awash");

            if (! $this->option('status') && ! $this->option('phone')) {
                return self::FAILURE;
            }
        }

        if ($ref = $this->option('status')) {
            $this->line(json_encode($g->verifyPayment($ref), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $this->option('phone')) {
            $this->line('Add --phone=09XXXXXXXX --amount=1.00 to send a test debit request.');

            return self::SUCCESS;
        }

        $phone = $g->normalisePhone($this->option('phone'));
        if (! $phone) {
            $this->error('That is not an Ethiopian mobile number.');

            return self::FAILURE;
        }

        $amount = round((float) $this->option('amount'), 2);
        $ref = 'AWASH-TEST-'.now()->format('ymdHis');

        if (! $this->confirm("Send a debit request for {$amount} ETB to {$phone} (reference {$ref})?", true)) {
            return self::SUCCESS;
        }

        try {
            $payload = $g->sendDebitRequest($ref, $amount, $phone, 'Niya integration test');
        } catch (\Throwable $e) {
            $this->error('4. DebitRequest: FAILED - '.$e->getMessage().' (details in storage/logs/laravel.log)');

            return self::FAILURE;
        }

        $this->info('4. DebitRequest: accepted');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("\nApprove it on the phone, then run:\n  php artisan awash:test --status={$ref}");

        return self::SUCCESS;
    }
}

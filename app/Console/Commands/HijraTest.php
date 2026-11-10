<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Talk to Hijra Bank from the server, without the apps.
 *
 *   php artisan hijra:test                         token only
 *   php artisan hijra:test --phone=0911223344 --amount=1.00
 *                                                  push a real USSD prompt (UAT)
 *   php artisan hijra:test --status=HIJRA-TEST-...  ask the status of a reference
 *
 * A test push is sent with a HIJRA-TEST- reference, which no contribution
 * carries, so nothing in Equb is ever credited by it.
 */
class HijraTest extends Command
{
    protected $signature = 'hijra:test {--phone=} {--amount=1.00} {--status=}';

    protected $description = 'Check the Hijra Bank USSD push integration (token, push, status)';

    public function handle(PaymentGatewayManager $gateways): int
    {
        $g = $gateways->tryGet('hijra');

        if (! $g) {
            $this->error('The hijra gateway is not registered (config/payments.php).');

            return self::FAILURE;
        }

        if (! $g->isConfigured()) {
            $this->error('Hijra is registered but not configured. Set HIJRA_TOKEN_URL, HIJRA_BASE_URL, HIJRA_CLIENT_ID, HIJRA_CLIENT_SECRET.');

            return self::FAILURE;
        }

        try {
            $token = $g->accessToken(true);
            $this->info('1. Token: OK ('.strlen($token).' chars)');
        } catch (\Throwable $e) {
            $this->error('1. Token: FAILED - '.$e->getMessage().' (details in storage/logs/laravel.log)');

            return self::FAILURE;
        }

        if ($ref = $this->option('status')) {
            $this->line(json_encode($g->verifyPayment($ref), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $this->option('phone')) {
            $this->line('Add --phone=09XXXXXXXX --amount=1.00 to send a test USSD push.');

            return self::SUCCESS;
        }

        $phone = $g->normalisePhone($this->option('phone'));
        if (! $phone) {
            $this->error('That is not an Ethiopian mobile number.');

            return self::FAILURE;
        }

        $amount = round((float) $this->option('amount'), 2);
        $ref = 'HIJRA-TEST-'.now()->format('ymdHis');

        if (! $this->confirm("Push a USSD prompt for {$amount} ETB to {$phone} (reference {$ref})?", true)) {
            return self::SUCCESS;
        }

        // Straight to the API: createOrder() looks the phone up from a
        // contribution, and a test has none.
        $env = app(\App\Services\EnvService::class);
        $base = rtrim((string) $env->get('HIJRA_BASE_URL'), '/');
        $request = Http::acceptJson()->withToken($token)->timeout(30);
        // Same rule as the gateway: only for a UAT host whose certificate
        // this server cannot validate (Hijra UAT is served on a bare IP).
        if (filter_var($env->get('HIJRA_INSECURE_SKIP_TLS_VERIFY', 'false'), FILTER_VALIDATE_BOOL)) {
            $request = $request->withoutVerifying();
        }
        $response = $request
            ->post($base.'/payments', [
                'paymentReference' => $ref,
                'customerPhone' => $phone,
                'amount' => $amount,
                'currency' => 'ETB',
                'additionalParams' => ['notes' => 'Niya integration test'],
            ]);

        // Record the test order like a real one, so Hijra's webhook for it
        // is recognised (200) instead of answered "Unknown order" (404).
        if (in_array($response->status(), [200, 202], true)) {
            \App\Models\PaymentGatewayOrder::updateOrCreate(
                ['gateway' => 'hijra', 'reference' => $ref],
                [
                    'external_order_id' => (string) ($response->json('correlationId') ?? ''),
                    'amount_minor' => (int) round($amount * 100),
                    'currency' => 'ETB',
                    'status' => strtolower((string) ($response->json('status') ?? 'received')),
                    'register_response' => $response->json(),
                ]
            );
        }

        $this->info('2. Initiate: HTTP '.$response->status());
        $this->line($response->body());
        $this->line("\nApprove the prompt on the phone, then run:\n  php artisan hijra:test --status={$ref}");

        return $response->status() === 202 || $response->status() === 200 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayOrder;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Talk to EthSwitch NPG without the app: register a test order, then check it.
 *
 *     php artisan npg:test                          register a 700 ETB order, print the card page link
 *     php artisan npg:test --amount=1               any amount, in birr
 *     php artisan npg:test --status=NPGTEST-...     ask EthSwitch what happened to it
 *
 * EthSwitch acceptance (UAT sign-off) tests. Use NPGTEST orders only, never a
 * member's real contribution:
 *
 *     php artisan npg:test --refund=NPGTEST-...     refund.do on a PAID test order (full amount, or --amount)
 *     php artisan npg:test --preauth                registerPreAuth.do: hold money, print the card page link
 *     php artisan npg:test --deposit=NPGTEST-...    deposit.do: complete a pre-authorization that was paid
 *
 *     add --fake-order       to send a made-up EthSwitch orderId   (expect: invalid order)
 *     add --wrong-password   to send a wrong merchant password     (expect: access denied)
 *     e.g. php artisan npg:test --refund=x --fake-order
 *          php artisan npg:test --deposit=NPGTEST-... --wrong-password
 *
 * Test orders are not contributions: nothing in Equb is credited by them.
 */
class NpgTest extends Command
{
    protected $signature = 'npg:test
                            {--amount= : Amount in birr (default 700; for --refund/--deposit the full order amount)}
                            {--status= : Check an order registered earlier, by its reference}
                            {--refund= : Refund a paid test order (refund.do), by its reference}
                            {--preauth : Register a pre-authorization (registerPreAuth.do)}
                            {--deposit= : Complete a pre-authorization (deposit.do), by its reference}
                            {--fake-order : Send a made-up orderId (negative test)}
                            {--wrong-password : Send a wrong merchant password (negative test)}';

    protected $description = 'Register a test order with EthSwitch NPG, check it, or run the refund / pre-authorization acceptance tests';

    public function handle(PaymentGatewayManager $gateways): int
    {
        $gateway = $gateways->tryGet('npg');

        if (! $gateway) {
            $this->error('The npg gateway is not registered. Check config/payments.php.');

            return self::FAILURE;
        }

        if (! $gateway->isConfigured()) {
            $this->error('NPG is not configured: set NPG_BASE_URL, NPG_USERNAME and NPG_PASSWORD in .env, then run php artisan config:clear.');

            return self::FAILURE;
        }

        if ($this->option('refund') !== null) {
            return $this->followUp($gateway, 'refund.do', (string) $this->option('refund'));
        }

        if ($this->option('deposit') !== null) {
            return $this->followUp($gateway, 'deposit.do', (string) $this->option('deposit'));
        }

        if ($this->option('preauth')) {
            return $this->preAuth($gateway);
        }

        if ($reference = $this->option('status')) {
            if ($this->option('fake-order') || $this->option('wrong-password')) {
                return $this->followUp($gateway, 'getOrderStatus.do', (string) $reference);
            }

            $result = $gateway->verifyPayment((string) $reference);

            $this->line('');
            $this->line('  reference : '.$reference);
            $this->line('  paid      : '.(($result['success'] ?? false) ? '<fg=green>YES</>' : '<fg=yellow>no</>'));
            $this->line('  status    : '.($result['status'] ?? data_get($result, 'data.response.OrderStatus', data_get($result, 'data.response.orderStatus', '-'))));
            $this->line('  message   : '.($result['message'] ?? '-'));
            $this->line('  EthSwitch : '.json_encode(data_get($result, 'data.response'), JSON_UNESCAPED_SLASHES));
            $this->line('');
            $this->line('  OrderStatus: 0 registered, not paid · 1 pre-authorized (held) · 2 PAID · 3 reversed · 4 refunded · 5 3-D Secure in progress · 6 declined');
            $this->line('');

            return self::SUCCESS;
        }

        $amount = (float) ($this->option('amount') ?? 700);
        $reference = 'NPGTEST-'.strtoupper(Str::random(10));

        $this->line('');
        $this->line('  Registering '.number_format($amount, 2).' ETB as '.$reference.' ...');

        try {
            $order = $gateway->createOrder($reference, $amount, 'Niya Equb test payment');
        } catch (\Throwable $e) {
            $this->error('  '.$e->getMessage());
            $this->line('  Details are in storage/logs/laravel.log (search "NPG register.do").');
            $this->line('  errorCode 5 "Access denied" usually means wrong credentials or this server IP is not whitelisted by EthSwitch.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('  Order registered.');
        $this->line('  EthSwitch orderId : '.$order['gateway_order_id']);
        $this->line('  amount sent       : '.$gateway->toMinor($amount).' (NPG_AMOUNT_MULTIPLIER applied)');
        $this->line('');
        $this->line('  1. Open this link in a browser and pay with the test card:');
        $this->line('     <fg=cyan>'.$order['checkout_url'].'</>');
        $this->line('     CHECK the amount on that page reads '.number_format($amount, 2).' ETB.');
        $this->line('  2. After paying you are sent back to '.$order['return_url']);
        $this->line('  3. Then run:  php artisan npg:test --status='.$reference);
        $this->line('');

        return self::SUCCESS;
    }

    /** registerPreAuth.do: like a purchase, but the money is only held until deposit.do. */
    protected function preAuth($gateway): int
    {
        $amount = (float) ($this->option('amount') ?? 700);
        $minor = $gateway->toMinor($amount);
        $reference = 'NPGTEST-PA-'.strtoupper(Str::random(8));

        $this->line('');
        $this->line('  Pre-authorizing '.number_format($amount, 2).' ETB as '.$reference.' ...');

        try {
            $result = $gateway->testRequest('registerPreAuth.do', [
                'orderNumber' => $reference,
                'amount' => $minor,
                'currency' => $gateway->currencyCode(),
                'returnUrl' => $gateway->returnUrl(),
                'description' => 'Niya Equb pre-authorization test',
                'language' => 'en',
            ], (bool) $this->option('wrong-password'));
        } catch (\Throwable $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $body = is_array($result['body']) ? $result['body'] : [];
        $orderId = (string) (data_get($body, 'orderId') ?? '');
        $formUrl = (string) (data_get($body, 'formUrl') ?? '');

        $this->printAnswer('registerPreAuth.do', $reference, $result);

        if ($orderId === '' || $formUrl === '') {
            return self::FAILURE;
        }

        // Stored like any test order so --status and --deposit can find it.
        PaymentGatewayOrder::updateOrCreate(
            ['gateway' => 'npg', 'reference' => $reference],
            [
                'external_order_id' => $orderId,
                'checkout_url' => $formUrl,
                'amount_minor' => $minor,
                'currency' => $gateway->currencyCode(),
                'status' => 'registered',
                'register_response' => $body,
            ]
        );

        $this->line('  1. Open this link and pay with the test card (the money is only HELD):');
        $this->line('     <fg=cyan>'.$formUrl.'</>');
        $this->line('  2. Check it:     php artisan npg:test --status='.$reference.'   (expect OrderStatus 1)');
        $this->line('  3. Complete it:  php artisan npg:test --deposit='.$reference.'  (expect errorCode 0)');
        $this->line('');

        return self::SUCCESS;
    }

    /** refund.do / deposit.do / getOrderStatus.do against an order we registered (or a fake one). */
    protected function followUp($gateway, string $path, string $reference): int
    {
        $fake = (bool) $this->option('fake-order');
        $order = PaymentGatewayOrder::query()->where('gateway', 'npg')->where('reference', $reference)->first();

        if (! $fake && (! $order || blank($order->external_order_id))) {
            $this->error('  No NPG order with reference '.$reference.' on this server.');

            return self::FAILURE;
        }

        if (! $fake && $path === 'refund.do' && ! str_starts_with($reference, 'NPGTEST-')) {
            $this->error('  Refund tests are only allowed on NPGTEST- orders, never on a member\'s contribution.');

            return self::FAILURE;
        }

        $orderId = $fake ? (string) Str::uuid() : (string) $order->external_order_id;
        $params = ['orderId' => $orderId];

        if ($path === 'getOrderStatus.do') {
            $params['language'] = 'en';
        } else {
            $params['amount'] = $this->option('amount') !== null
                ? $gateway->toMinor((float) $this->option('amount'))
                : (int) ($order->amount_minor ?? $gateway->toMinor(1));
        }

        $this->line('');
        $this->line('  Sending '.$path.' for '.($fake ? 'a made-up orderId '.$orderId : $reference.' (orderId '.$orderId.')')
            .($this->option('wrong-password') ? ' with a WRONG password' : '').' ...');

        try {
            $result = $gateway->testRequest($path, $params, (bool) $this->option('wrong-password'));
        } catch (\Throwable $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printAnswer($path, $fake ? 'made-up order' : $reference, $result);

        return self::SUCCESS;
    }

    protected function printAnswer(string $path, string $reference, array $result): void
    {
        $body = $result['body'];
        $code = is_array($body) ? (data_get($body, 'errorCode') ?? data_get($body, 'ErrorCode') ?? '0') : '?';
        $message = is_array($body) ? (data_get($body, 'errorMessage') ?? data_get($body, 'ErrorMessage') ?? 'Success') : '-';

        $this->line('');
        $this->line('  '.$path.' for '.$reference);
        $this->line('  errorCode : '.((string) $code === '0' ? '<fg=green>0</>' : '<fg=yellow>'.$code.'</>'));
        $this->line('  message   : '.$message);
        $this->line('  EthSwitch : '.(is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : $body));
        $this->line('');
    }
}

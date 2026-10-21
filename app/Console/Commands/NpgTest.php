<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Talk to EthSwitch NPG without the app: register a test order, then check it.
 *
 *     php artisan npg:test                      register a 700 ETB order, print the card page link
 *     php artisan npg:test --amount=1           any amount, in birr
 *     php artisan npg:test --status=NPGTEST-... ask EthSwitch what happened to it
 *
 * Test orders are not contributions: nothing in Equb is credited by them.
 */
class NpgTest extends Command
{
    protected $signature = 'npg:test
                            {--amount=700 : Amount in birr}
                            {--status= : Check an order registered earlier, by its reference}';

    protected $description = 'Register a test order with EthSwitch NPG, or check the status of one';

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

        if ($reference = $this->option('status')) {
            $result = $gateway->verifyPayment((string) $reference);

            $this->line('');
            $this->line('  reference : '.$reference);
            $this->line('  paid      : '.(($result['success'] ?? false) ? '<fg=green>YES</>' : '<fg=yellow>no</>'));
            $this->line('  status    : '.($result['status'] ?? data_get($result, 'data.response.OrderStatus', data_get($result, 'data.response.orderStatus', '-'))));
            $this->line('  message   : '.($result['message'] ?? '-'));
            $this->line('  EthSwitch : '.json_encode(data_get($result, 'data.response'), JSON_UNESCAPED_SLASHES));
            $this->line('');
            $this->line('  OrderStatus: 0 registered, not paid · 2 PAID · 3 reversed · 5 3-D Secure in progress · 6 declined');
            $this->line('');

            return self::SUCCESS;
        }

        $amount = (float) $this->option('amount');
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
}

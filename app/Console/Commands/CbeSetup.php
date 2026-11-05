<?php

namespace App\Console\Commands;

use App\Services\EnvService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;

/**
 * Configure the CBE production integration.
 *
 *   php artisan cbe:setup            create production credentials (once) and show status
 *   php artisan cbe:setup --show     show status and credentials only
 *
 * Values live in the settings table (EnvService), so they survive
 * config:cache and redeploys. The client secret is generated once and never
 * replaced unless --rotate is passed, because CBE stores it on their side.
 */
class CbeSetup extends Command
{
    protected $signature = 'cbe:setup
        {--show : Only print the current configuration}
        {--rotate : Generate a NEW client secret (CBE must be given it)}
        {--allowed-ips= : Comma-separated CBE hosts allowed to call /api/cbe}
        {--show-in-apps= : true/false, offer CBE as a payment option in the apps}';

    protected $description = 'Configure the CBE Mobile Banking (Unified Outgoing) production API';

    public function handle(EnvService $env): int
    {
        if (! $this->option('show')) {
            $defaults = [
                'CBE_CLIENT_ID' => 'niya-cbe-prod',
                'CBE_CREDIT_ACCOUNT' => '1000800212522',
                'CBE_TIN' => '0105183255',
                'CBE_ALLOWED_IPS' => '172.30.113.10,172.30.113.11,172.30.113.12,127.0.0.1',
                'CBE_SHOW_IN_APPS' => 'false',
            ];

            foreach ($defaults as $key => $value) {
                if ((string) $env->get($key, '') === '') {
                    $env->set($key, $value);
                }
            }

            if ((string) $env->get('CBE_CLIENT_SECRET', '') === '' || $this->option('rotate')) {
                $env->set('CBE_CLIENT_SECRET', bin2hex(random_bytes(24)));
                $this->warn('A new client secret was generated. Share it with CBE privately.');
            }

            if ($ips = $this->option('allowed-ips')) {
                $env->set('CBE_ALLOWED_IPS', (string) $ips);
            }

            if (($show = $this->option('show-in-apps')) !== null) {
                $env->set('CBE_SHOW_IN_APPS', filter_var($show, FILTER_VALIDATE_BOOL) ? 'true' : 'false');
            }

            EnvService::forgetCache();
        }

        $gateway = app(PaymentGatewayManager::class)->tryGet('cbe');

        $this->table(['Setting', 'Value'], [
            ['Registered', $gateway ? 'yes' : 'NO (check config/payments.php)'],
            ['Configured', $gateway?->isConfigured() ? 'yes' : 'no'],
            ['Listed in apps', app(PaymentGatewayManager::class)->isListed('cbe') ? 'yes' : 'no'],
            ['client_id', (string) $env->get('CBE_CLIENT_ID', '')],
            ['client_secret', (string) $env->get('CBE_CLIENT_SECRET', '')],
            ['Credit account', (string) $env->get('CBE_CREDIT_ACCOUNT', '')],
            ['TIN', (string) $env->get('CBE_TIN', '')],
            ['Allowed IPs', (string) $env->get('CBE_ALLOWED_IPS', '')],
        ]);

        return self::SUCCESS;
    }
}

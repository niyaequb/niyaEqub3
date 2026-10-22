<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Services\EnvService;
use Illuminate\Support\Facades\Log;

/**
 * The register of banks.
 *
 * Everything that needs to know which banks exist asks this — route
 * registration, request validation, the admin filter, the settings screen, the
 * API's provider list. Nothing else reads config/payments.php, and nothing else
 * names a bank in code.
 *
 * That is the whole point. When CBE is added, this class does not change; the
 * config array gains a block and every derived behaviour follows. A grep for
 * 'dashen' outside config, the enum and DashenGateway should find nothing, and
 * if it does, that is a place a second bank would have been forgotten.
 *
 * Gateways are built once per request and reused. They are stateless apart
 * from their config, and building one parses a PEM key.
 */
class PaymentGatewayManager
{
    protected EnvService $env;

    /** @var array<string, PaymentGateway> */
    protected array $resolved = [];

    public function __construct(EnvService $env)
    {
        $this->env = $env;
    }

    /**
     * Every registered gateway, configured or not.
     *
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        $gateways = [];

        foreach (array_keys((array) config('payments.gateways', [])) as $slug) {
            $gateway = $this->tryGet((string) $slug);

            if ($gateway) {
                $gateways[$slug] = $gateway;
            }
        }

        return $gateways;
    }

    /**
     * Gateways that can actually take a payment right now.
     *
     * This is what the apps are offered. A bank whose credentials are missing
     * is withheld rather than shown and then failing at the moment a member
     * tries to pay — the failure would look like a broken app rather than an
     * unfinished integration, and the member would have no way to tell.
     *
     * @return array<string, PaymentGateway>
     */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (PaymentGateway $g): bool => $g->isConfigured());
    }

    /** @return array<int, string> */
    public function slugs(): array
    {
        return array_keys((array) config('payments.gateways', []));
    }

    /** @return array<int, string> */
    public function enabledSlugs(): array
    {
        return array_keys($this->enabled());
    }

    public function has(string $slug): bool
    {
        return array_key_exists($slug, (array) config('payments.gateways', []));
    }

    /**
     * Resolve one gateway, or throw.
     *
     * Throws rather than returning null because every caller that names a
     * gateway has already checked it exists — a miss here is a bug or a
     * malformed route, not a user error, and swallowing it would turn a
     * mis-registered bank into payments that quietly do nothing.
     */
    public function get(string $slug): PaymentGateway
    {
        $gateway = $this->tryGet($slug);

        if (! $gateway) {
            throw new \InvalidArgumentException("Unknown payment gateway [{$slug}].");
        }

        return $gateway;
    }

    /** Resolve one gateway, or null if it is unknown or misdeclared. */
    public function tryGet(string $slug): ?PaymentGateway
    {
        if (isset($this->resolved[$slug])) {
            return $this->resolved[$slug];
        }

        $config = config("payments.gateways.{$slug}");

        if (! is_array($config) || empty($config['class'])) {
            return null;
        }

        $class = $config['class'];

        if (! class_exists($class) || ! is_subclass_of($class, PaymentGateway::class)) {
            // A registered bank whose class is missing is a deployment
            // mistake. Log it loudly and carry on: one broken entry must not
            // take down the banks that do work.
            Log::error('Payment gateway is registered but not usable', [
                'gateway' => $slug,
                'class' => $class,
            ]);

            return null;
        }

        return $this->resolved[$slug] = new $class($this->env, $config + ['slug' => $slug]);
    }

    /** The gateway used when a client does not name one. */
    public function default(): ?PaymentGateway
    {
        return $this->tryGet((string) config('payments.default', 'dashen'));
    }

    /**
     * Payment methods a member may submit.
     *
     * Enabled banks, and nothing else. Offline and manual collection were
     * withdrawn — every contribution now goes through a bank — so there is no
     * longer a route that records one as settled without money having moved.
     *
     * Built from the register rather than hardcoded, so a bank becomes payable
     * the moment its credentials are in place and stops being payable the
     * moment they are removed.
     *
     * @return array<int, string>
     */
    public function acceptedMethods(): array
    {
        return $this->enabledSlugs();
    }

    /**
     * Payment methods a member may submit.
     *
     * acceptedMethods() minus any bank that has been switched off for members
     * (see isListed()). The member is passed so a per-member rule can be added
     * here later without touching the callers; nothing narrows on it today.
     *
     * @return array<int, string>
     */
    public function acceptedMethodsFor(mixed $user = null): array
    {
        return array_values(array_filter(
            $this->enabledSlugs(),
            fn (string $slug): bool => $this->isListed($slug)
        ));
    }

    /**
     * What the apps need in order to draw a bank picker and present an order.
     *
     * WHICH APP IS ASKING DECIDES THE LIST.
     *
     *   miniapp (the default)  banks paid inside a super-app: Dashen. Every
     *                          client that does not say which it is gets this,
     *                          so the mini app and older phone builds see
     *                          exactly what they always saw.
     *   mobile                 banks the phone app can pay through itself: the
     *                          EthSwitch card checkout. Only the new phone
     *                          build sends channel=mobile.
     *
     * A phone with no card gateway available (not configured, or switched off)
     * falls back to the super-app list. That is what it got before, and the app
     * presents it as "pay in the Dashen app".
     *
     * @return array<int, array<string, mixed>>
     */
    public function clientCatalogue(?string $channel = null): array
    {
        $channel = $channel === 'mobile' ? 'mobile' : 'miniapp';

        $pick = fn (string $wanted): array => array_filter(
            $this->enabled(),
            fn (PaymentGateway $g, string $slug): bool => in_array($wanted, $this->channelsOf($slug), true)
                && $this->isListed($slug),
            ARRAY_FILTER_USE_BOTH
        );

        $gateways = $pick($channel);

        if ($gateways === [] && $channel === 'mobile') {
            $gateways = $pick('miniapp');
        }

        return array_values(array_map(
            fn (PaymentGateway $g): array => $g->clientConfig() + [
                'can_verify_settlement' => $g->canVerifySettlement(),
            ],
            $gateways
        ));
    }

    /** @return array<int, string> */
    protected function channelsOf(string $slug): array
    {
        $channels = config("payments.gateways.{$slug}.channels");

        return is_array($channels) && $channels !== [] ? array_values($channels) : ['miniapp'];
    }

    /**
     * Whether members may use this bank.
     *
     * ON unless it has been switched off: the gateway's `listed_setting`
     * (NPG_SHOW_IN_APPS for EthSwitch) set to false, off, no or 0. Any other
     * value, or none at all, is on.
     *
     * There is deliberately no "testers only" mode any more. It needed a
     * correctly typed phone list on the server, and a placeholder left in that
     * list silently hid the bank from everyone. The gate that keeps a bank away
     * from members who should not see it is `channels`: EthSwitch is offered
     * on the mobile channel only, and only the new phone build asks for it.
     */
    public function isListed(string $slug): bool
    {
        $key = config("payments.gateways.{$slug}.listed_setting");

        if (! $key) {
            return true;
        }

        $value = strtolower(trim((string) $this->env->get((string) $key, '')));

        return ! in_array($value, ['0', 'false', 'no', 'off'], true);
    }
}

<?php

namespace App\Providers;

use App\Services\EnvService;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Re-applies administrator-saved settings on top of config().
 *
 * WHY THIS EXISTS
 *
 * config() is resolved once, at boot, from the environment — and on a cached
 * config (`php artisan config:cache`, which every sane production deploy runs)
 * it is resolved at *build* time and frozen into a PHP array. Neither moment
 * can see a value an administrator saves from the Settings page afterwards.
 *
 * Most of the application reads settings through EnvService, which consults
 * the database and is therefore always current. But config/services.php
 * mirrors some of the same keys for code that only needs to read them, and
 * `config('services.equb.auto_draw_enabled')` would go on serving whatever was
 * true when the image was built. This provider closes that gap: after the
 * stored settings are available, the config entries derived from them are
 * written again with the saved values.
 *
 * Scope is deliberately limited to keys the Settings page can actually write.
 * A key that is only ever set as a platform environment variable is already
 * correct in config() and is left alone.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Equb settings, as `config key => [stored key, cast]`.
     */
    protected const EQUB = [
        'draw_delay' => ['EQUB_DRAW_DELAY', 'int'],
        'auto_draw_enabled' => ['EQUB_AUTO_DRAW_ENABLED', 'bool'],
        'auto_start_enabled' => ['EQUB_AUTO_START_ENABLED', 'bool'],
        'members_per_draw' => ['EQUB_MEMBERS_PER_DRAW', 'int'],
        'restrict_draw_frequency' => ['EQUB_RESTRICT_DRAW_FREQUENCY', 'bool'],
        'enforce_draw_schedule' => ['EQUB_ENFORCE_DRAW_SCHEDULE', 'bool'],
    ];

    public function boot(): void
    {
        // A web request reads the settings once and dies. A queue worker does
        // not: it stays up for hours, so without this it would go on sending
        // SMS with the API key that was current when it started, long after
        // someone changed it in the admin panel. Cheap to avoid — one query
        // per job — and the alternative is a bug that only appears in
        // production and only after a settings change.
        Event::listen(JobProcessing::class, static function (): void {
            EnvService::forgetCache();
        });

        // THE HEALTH CHECK MUST NEVER TOUCH THE DATABASE.
        //
        // Everything below reads settings, and reading settings means a query.
        // Running that unconditionally put a database round trip on the boot
        // path of EVERY request — including GET /up, which is the endpoint the
        // hosting platform polls to decide whether this instance is alive.
        //
        // That is a bad trade in exactly the situation it matters. If the
        // database goes slow or unreachable, a health check that depends on it
        // starts timing out; the platform concludes the instance is dead and
        // pulls it out of rotation; and an application that was merely
        // degraded starts answering nothing at all. The visible symptom is a
        // 504 where there used to be a 500 — the app stops replying rather
        // than replying with an error, which is strictly harder to diagnose.
        //
        // A health check answers one question: is this process alive and
        // serving? Every dependency it acquires is another way for a working
        // process to be declared dead.
        if ($this->isHealthCheck()) {
            return;
        }

        $env = $this->app->make(EnvService::class);

        // One query, and on a deployment where nobody has saved anything yet,
        // no work at all: config() already reflects the environment, which is
        // the only source there is.
        if ($env->all() === []) {
            return;
        }

        $this->applyEqub($env);
        $this->applyFirebase($env);
        $this->applyGateways($env);
    }

    /**
     * Is this the platform's health probe?
     *
     * The path comes from the `health:` argument in bootstrap/app.php. Read
     * off the request rather than the router, because this runs during boot,
     * before a route has been matched.
     */
    protected function isHealthCheck(): bool
    {
        if ($this->app->runningInConsole()) {
            return false;
        }

        return trim((string) $this->app['request']->getPathInfo(), '/') === 'up';
    }

    protected function applyEqub(EnvService $env): void
    {
        foreach (self::EQUB as $name => [$key, $cast]) {
            $value = $env->get($key);

            if ($value === null || $value === '') {
                continue;
            }

            config([
                "services.equb.{$name}" => $cast === 'int'
                    ? (int) $value
                    : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    protected function applyFirebase(EnvService $env): void
    {
        $credentials = $env->get('FIREBASE_CREDENTIALS');

        if ($credentials !== null && $credentials !== '') {
            config([
                'services.firebase.credentials' => $credentials,
                'services.firebase.service_account_path' => $credentials,
            ]);
        }

        $projectId = $env->get('FIREBASE_PROJECT_ID');

        if ($projectId !== null && $projectId !== '') {
            config(['services.firebase.project_id' => $projectId]);
        }
    }

    /**
     * Bank credentials, for every registered gateway.
     *
     * Derived from config/payments.php rather than listed here, so adding CBE
     * or Awash needs no change in this file — the same principle the rest of
     * the payment layer is built on: banks are named in exactly one place.
     */
    protected function applyGateways(EnvService $env): void
    {
        foreach ((array) config('payments.gateways', []) as $slug => $gateway) {
            $prefix = $gateway['env_prefix'] ?? strtoupper((string) $slug);

            foreach ($env->getGatewayConfig($prefix) as $key => $value) {
                if ($value === '') {
                    continue;
                }

                config(['services.'.$slug.'.'.strtolower($key) => $value]);
            }
        }
    }
}

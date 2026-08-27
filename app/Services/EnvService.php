<?php

namespace App\Services;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runtime settings an administrator can change from the Settings page.
 *
 * WHY THIS IS NOT A .ENV FILE ANY MORE
 *
 * It was, and on a single long-lived server that is fine: the file is there,
 * it is writable, and it survives a restart. Niya does not run on one of
 * those. It runs on DigitalOcean App Platform, where the container is rebuilt
 * from the build image on every deploy, every restart and every scale event.
 * Three consequences, any one of which is fatal on its own:
 *
 *   1. A file written at runtime lives until the next restart and no longer.
 *   2. `.env` is not in the image at all — it is gitignored, correctly, and
 *      creating one by hand in the web console creates it in that one
 *      container.
 *   3. The platform injects real environment variables, and Laravel's dotenv
 *      is immutable: it never overwrites a variable that is already set. So
 *      even a `.env` that somehow persisted would be ignored for every key
 *      configured in the platform dashboard.
 *
 * The symptom of all three is identical and quietly awful — the page says
 * "saved successfully" and the value is gone on the next page load.
 *
 * So the store of record is the database: the one writable, shared, durable
 * thing this application already has, and the same `global_settings` table the
 * Legal, Support, Social and App Version tabs have always used.
 *
 * RESOLUTION ORDER
 *
 *   stored value (database)  →  environment variable  →  caller's default
 *
 * A stored empty string counts as "not set" and falls through. That is what
 * makes clearing a field in the admin panel do the obvious thing — hand the
 * key back to the platform's environment variable, or to the default — rather
 * than pinning it to empty forever.
 *
 * The environment stays in the chain deliberately. It is how a fresh
 * deployment boots before anyone has opened the Settings page, how secrets can
 * be kept in the platform dashboard instead of the database if an operator
 * prefers, and how local development keeps working from a plain `.env`.
 */
class EnvService
{
    /**
     * The `group` these rows carry in `global_settings`.
     */
    public const GROUP = 'env';

    /**
     * Prefix on the stored `key`.
     *
     * `global_settings.key` is unique across every tab, and MySQL's default
     * collation is case-insensitive — so an environment key `APP_VERSION`
     * and an App Version tab field `app_version` are the same row. Without
     * this prefix, saving one would silently overwrite the other.
     */
    protected const KEY_PREFIX = 'env:';

    protected string $envPath;

    /**
     * Stored overrides, read once per request.
     *
     * Static rather than per-instance: FabricGateway alone asks for a dozen
     * keys while building a single order, and every service that reads a
     * setting resolves its own EnvService. One query per request, not per
     * question.
     *
     * @var array<string, string>|null
     */
    protected static ?array $stored = null;

    public function __construct()
    {
        $this->envPath = base_path('.env');
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * Resolve a setting: stored value, then environment, then default.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $stored = static::stored();

        if (isset($stored[$key]) && $stored[$key] !== '') {
            return $stored[$key];
        }

        return env($key, $default);
    }

    /**
     * Every stored override, keyed without the storage prefix.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return static::stored();
    }

    /**
     * Load the stored overrides for this request.
     *
     * @return array<string, string>
     */
    protected static function stored(): array
    {
        if (static::$stored !== null) {
            return static::$stored;
        }

        try {
            $rows = GlobalSetting::query()
                ->where('group', self::GROUP)
                ->pluck('value', 'key')
                ->all();
        } catch (Throwable $e) {
            // No database yet. This is the normal state during `migrate` on a
            // fresh deployment, and the abnormal one when the connection is
            // down. Either way the environment is still readable, so the
            // application boots on it rather than trading a missing setting
            // for a white screen.
            return static::$stored = [];
        }

        $settings = [];

        foreach ($rows as $key => $value) {
            if (! str_starts_with((string) $key, self::KEY_PREFIX)) {
                continue;
            }

            $settings[substr((string) $key, strlen(self::KEY_PREFIX))] = (string) $value;
        }

        return static::$stored = $settings;
    }

    /**
     * Drop the per-request memo.
     *
     * Only needed by tests and by long-running workers that must pick up a
     * change made by the web process mid-run.
     */
    public static function forgetCache(): void
    {
        static::$stored = null;
    }

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * Persist one setting.
     *
     * Returns false rather than throwing so a caller can report which keys
     * failed; setMultiple() turns that into an exception, because a settings
     * form that reports success over a failed write is worse than one that
     * reports an error.
     */
    public function set(string $key, ?string $value): bool
    {
        $value = (string) ($value ?? '');

        try {
            GlobalSetting::updateOrCreate(
                ['key' => self::KEY_PREFIX.$key],
                ['value' => $value, 'group' => self::GROUP],
            );
        } catch (Throwable $e) {
            Log::error('Could not persist setting', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Keep this request consistent with what was just written.
        static::stored();
        static::$stored[$key] = $value;

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        // Mirror into .env where there is one and it is writable, so a
        // developer editing settings locally still sees them in the file they
        // expect. Best effort by design: on App Platform there is no .env, and
        // failing to write a mirror must never fail the save.
        $this->mirrorToEnvFile($key, $value);

        return true;
    }

    /**
     * Persist several settings, or fail loudly.
     *
     * @param  array<string, string|null>  $values
     *
     * @throws \RuntimeException if any key could not be written
     */
    public function setMultiple(array $values): bool
    {
        $failed = [];

        foreach ($values as $key => $value) {
            if (! $this->set($key, $value ?? '')) {
                $failed[] = $key;
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(
                'Could not save '.implode(', ', $failed).'. '
                .'The settings table could not be written — check the database '
                .'connection and that migrations have run.'
            );
        }

        return true;
    }

    /**
     * Write the key into the local .env file, if that is a thing that exists.
     */
    protected function mirrorToEnvFile(string $key, string $value): void
    {
        // A real newline in a .env value throws InvalidFileException out of
        // phpdotenv at boot, which takes the whole application down and is
        // very hard to trace back to a settings save. Values that carry one —
        // a PEM block, say — live in the database only.
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            return;
        }

        try {
            if (! File::exists($this->envPath) || ! is_writable($this->envPath)) {
                return;
            }

            $envContent = File::get($this->envPath);

            // Quote anything with whitespace or a comment marker in it, and
            // quote empties so the line reads as deliberately blank.
            $escapedValue = $value;
            if (preg_match('/[#\s"\'\\\\]/', $value) || $value === '') {
                $escapedValue = '"'.addslashes($value).'"';
            }

            $pattern = '/^'.preg_quote($key, '/').'=.*/m';

            $envContent = preg_match($pattern, $envContent)
                ? preg_replace($pattern, $key.'='.$escapedValue, $envContent)
                : rtrim($envContent, "\r\n")."\n".$key.'='.$escapedValue."\n";

            File::put($this->envPath, $envContent);
        } catch (Throwable $e) {
            Log::debug('Skipped .env mirror', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The settings one bank's gateway reads, keyed WITHOUT its prefix.
     *
     * Prefix-driven rather than a method per bank. Niya will collect through
     * Dashen, CBE, Awash and more, and a getCbeConfig() beside a getAwashConfig()
     * beside a getDashenConfig() would be the same dozen lines copied ten times,
     * with the tenth copy quietly missing whichever key was added last.
     *
     * BASE_URL, TOKEN_PATH and ORDER_QUERY_PATH default empty on purpose. A
     * gateway with no ORDER_QUERY_PATH reports canVerifySettlement() false and
     * settlement fails closed rather than crediting a member on a claim nobody
     * can confirm.
     *
     * @return array<string, string>
     */
    public function getGatewayConfig(string $prefix): array
    {
        $prefix = strtoupper($prefix);

        $values = [];

        foreach (self::GATEWAY_KEYS as $key => $default) {
            $values[$key] = (string) $this->get($prefix.'_'.$key, $default);
        }

        // Returned with real line breaks. The literal backslash-n form is
        // purely a storage detail — a single .env line cannot hold a PEM
        // block — and nothing reading this config should have to know it.
        $values['PUBLIC_KEY'] = str_replace('\n', "\n", $values['PUBLIC_KEY']);

        return $values;
    }

    /**
     * Write one bank's gateway settings.
     *
     * Keys are given WITHOUT the prefix; it is applied here. Only keys actually
     * present in $values are written, so a form rendering a subset of the
     * fields cannot blank the ones it did not show.
     *
     * @param  array<string, string|null>  $values
     */
    public function setGatewayConfig(string $prefix, array $values): bool
    {
        $prefix = strtoupper($prefix);

        $write = [];

        foreach ($values as $key => $value) {
            $key = strtoupper($key);

            if (! array_key_exists($key, self::GATEWAY_KEYS)) {
                continue;
            }

            $value = (string) ($value ?? '');

            if ($key === 'PUBLIC_KEY') {
                // Collapsed to literal backslash-n, the only form a single
                // .env line can carry. getGatewayConfig() puts them back.
                $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
            }

            $write[$prefix.'_'.$key] = $value;
        }

        return $write === [] ? true : $this->setMultiple($write);
    }

    /**
     * Every setting a gateway understands, and its default.
     *
     * One list shared by every bank. Adding a setting here makes it available
     * to all of them at once, which is the point.
     */
    public const GATEWAY_KEYS = [
        'MERCHANT_CODE' => '',
        'MERCHANT_APP_ID' => '',
        'FABRIC_APP_ID' => '',
        'MINI_APP_CODE' => '',
        'SHORT_CODE' => '',
        'APP_SECRET' => '',
        'PUBLIC_KEY' => '',
        // Path to a .pem file, preferred over the inline value. A PEM block is
        // multi-line and a .env value is not — see FabricGateway::publicKey().
        'PUBLIC_KEY_PATH' => '',
        'STAGE' => 'uat',
        'TIMEOUT_EXPRESS' => '120m',
        'SIGN_KEYS' => '',
        'RSA_PADDING' => 'oaep',
        'BASE_URL' => '',
        'TOKEN_PATH' => '',
        'ORDER_QUERY_PATH' => '',
    ];

    /**
     * Get all AFRO SMS-related env values
     */
    public function getAfroConfig(): array
    {
        return [
            'AFRO_API_KEY' => $this->get('AFRO_API_KEY', ''),
            'AFRO_IDENTIFIER_ID' => $this->get('AFRO_IDENTIFIER_ID', ''),
            'AFRO_SENDER_NAME' => $this->get('AFRO_SENDER_NAME', ''),
            'AFRO_BASE_URL' => $this->get('AFRO_BASE_URL', 'https://api.afromessage.com/api'),
            'AFRO_OTP_EXPIRES_IN_SECONDS' => $this->get('AFRO_OTP_EXPIRES_IN_SECONDS', '12'),
            'AFRO_OPT_LENGTH' => $this->get('AFRO_OPT_LENGTH', '4'),
            'SHORT_CODE' => $this->get('SHORT_CODE', '4'),
            'SMS_MODE' => $this->get('SMS_MODE', '2'),
        ];
    }

    /**
     * Set AFRO SMS configuration
     */
    public function setAfroConfig(array $config): bool
    {
        return $this->setMultiple([
            'AFRO_API_KEY' => $config['api_key'] ?? '',
            'AFRO_IDENTIFIER_ID' => $config['identifier_id'] ?? '',
            'AFRO_SENDER_NAME' => $config['sender_name'] ?? '',
            'AFRO_BASE_URL' => $config['base_url'] ?? '',
            'AFRO_OTP_EXPIRES_IN_SECONDS' => $config['otp_expires_in_seconds'] ?? '12',
            'AFRO_OPT_LENGTH' => $config['opt_length'] ?? '4',
            'SHORT_CODE' => $config['short_code'] ?? '4',
            'SMS_MODE' => $config['sms_mode'] ?? '2',
        ]);
    }

    /**
     * Get all GEEZ SMS-related env values
     */
    public function getGeezConfig(): array
    {
        return [
            'GEEZ_SMS_TOKEN' => $this->get('GEEZ_SMS_TOKEN', ''),
            'GEEZ_SMS_SHORTCODE_ID' => $this->get('GEEZ_SMS_SHORTCODE_ID', ''),
            'GEEZ_SMS_BASE_URL' => $this->get('GEEZ_SMS_BASE_URL', ''),
            'OTP_TTL_MINUTES' => $this->get('OTP_TTL_MINUTES', '5'),
        ];
    }

    /**
     * Set GEEZ SMS configuration
     */
    public function setGeezConfig(array $config): bool
    {
        return $this->setMultiple([
            'GEEZ_SMS_TOKEN' => $config['sms_token'] ?? '',
            'GEEZ_SMS_SHORTCODE_ID' => $config['sms_shortcode_id'] ?? '',
            'GEEZ_SMS_BASE_URL' => $config['sms_base_url'] ?? '',
            'OTP_TTL_MINUTES' => $config['otp_ttl_minutes'] ?? '5',
        ]);
    }

    /**
     * Get all Equb-related env values
     */
    public function getEqubConfig(): array
    {
        return [
            'EQUB_DRAW_DELAY' => $this->get('EQUB_DRAW_DELAY', '30'),
            'EQUB_AUTO_DRAW_ENABLED' => $this->get('EQUB_AUTO_DRAW_ENABLED', 'false'),
            'EQUB_AUTO_START_ENABLED' => $this->get('EQUB_AUTO_START_ENABLED', 'true'),
            'EQUB_RESTRICT_DRAW_FREQUENCY' => $this->get('EQUB_RESTRICT_DRAW_FREQUENCY', 'true'),
            'EQUB_ENFORCE_DRAW_SCHEDULE' => $this->get('EQUB_ENFORCE_DRAW_SCHEDULE', 'false'),
            'EQUB_MEMBERS_PER_DRAW' => $this->get('EQUB_MEMBERS_PER_DRAW', '50'),
        ];
    }

    /**
     * Set Equb configuration
     */
    public function setEqubConfig(array $config): bool
    {
        return $this->setMultiple([
            'EQUB_DRAW_DELAY' => $config['draw_delay'] ?? '30',
            'EQUB_AUTO_DRAW_ENABLED' => $config['auto_draw_enabled'] ?? 'false',
            'EQUB_AUTO_START_ENABLED' => $config['auto_start_enabled'] ?? 'true',
            'EQUB_RESTRICT_DRAW_FREQUENCY' => $config['restrict_draw_frequency'] ?? 'true',
            'EQUB_ENFORCE_DRAW_SCHEDULE' => $config['enforce_draw_schedule'] ?? 'false',
            'EQUB_MEMBERS_PER_DRAW' => $config['members_per_draw'] ?? '50',
        ]);
    }

    /**
     * Get all Firebase-related env values
     */
    public function getFirebaseConfig(): array
    {
        return [
            'FIREBASE_CREDENTIALS' => $this->get('FIREBASE_CREDENTIALS', 'storage/app/firebase/service-account.json'),
            'FIREBASE_PROJECT_ID' => $this->get('FIREBASE_PROJECT_ID', ''),
        ];
    }

    /**
     * Set Firebase configuration
     */
    public function setFirebaseConfig(array $config): bool
    {
        $values = [];
        if (isset($config['credentials'])) {
            $values['FIREBASE_CREDENTIALS'] = $config['credentials'];
        }
        if (isset($config['project_id'])) {
            $values['FIREBASE_PROJECT_ID'] = $config['project_id'];
        }

        return $this->setMultiple($values);
    }
}

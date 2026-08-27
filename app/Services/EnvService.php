<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class EnvService
{
    protected string $envPath;

    public function __construct()
    {
        $this->envPath = base_path('.env');
    }

    /**
     * Get value from .env file
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $value = env($key, $default);
        return $value;
    }

    /**
     * Set value in .env file
     */
    public function set(string $key, ?string $value): bool
    {
        if (!File::exists($this->envPath)) {
            return false;
        }

        $envContent = File::get($this->envPath);

        // Escape value if it contains special characters or spaces
        $escapedValue = $value;
        if (preg_match('/[#\s"\'\\\\]/', $value) || empty($value)) {
            $escapedValue = '"' . addslashes($value) . '"';
        }

        // Check if key exists (handle both quoted and unquoted values)
        if (preg_match("/^{$key}=(.*)/m", $envContent)) {
            // Update existing key
            $envContent = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$escapedValue}",
                $envContent
            );
        } else {
            // Add new key at the end
            $envContent .= "\n{$key}={$escapedValue}";
        }

        File::put($this->envPath, $envContent);

        // Update the environment variable in current process
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        return true;
    }

    /**
     * Set multiple values at once
     */
    public function setMultiple(array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value ?? '');
        }

        return true;
    }

    /**
     * Get all Dashen SuperApp env values.
     *
     * DASHEN_BASE_URL, DASHEN_TOKEN_PATH and DASHEN_ORDER_QUERY_PATH are the
     * three Dashen has not yet supplied. They default empty, which makes
     * DashenService fail closed rather than guess — see the comment on
     * verifyPayment().
     */
    public function getDashenConfig(): array
    {
        return [
            'DASHEN_MERCHANT_CODE' => $this->get('DASHEN_MERCHANT_CODE', ''),
            'DASHEN_MERCHANT_APP_ID' => $this->get('DASHEN_MERCHANT_APP_ID', ''),
            'DASHEN_FABRIC_APP_ID' => $this->get('DASHEN_FABRIC_APP_ID', ''),
            'DASHEN_MINI_APP_CODE' => $this->get('DASHEN_MINI_APP_CODE', ''),
            'DASHEN_SHORT_CODE' => $this->get('DASHEN_SHORT_CODE', ''),
            'DASHEN_APP_SECRET' => $this->get('DASHEN_APP_SECRET', ''),
            // Returned with real line breaks. The literal \n form is purely an
            // .env storage detail — a single line cannot hold a PEM block — and
            // nothing that reads this config should have to know about it.
            'DASHEN_PUBLIC_KEY' => str_replace('\n', "\n", (string) $this->get('DASHEN_PUBLIC_KEY', '')),
            'DASHEN_STAGE' => $this->get('DASHEN_STAGE', 'uat'),
            'DASHEN_BASE_URL' => $this->get('DASHEN_BASE_URL', ''),
            'DASHEN_TOKEN_PATH' => $this->get('DASHEN_TOKEN_PATH', ''),
            'DASHEN_ORDER_QUERY_PATH' => $this->get('DASHEN_ORDER_QUERY_PATH', ''),
        ];
    }

    /**
     * Set Dashen SuperApp configuration.
     *
     * The public key is stored with real newlines collapsed to literal \n, the
     * only form a single .env line can carry. DashenService puts them back.
     */
    public function setDashenConfig(array $config): bool
    {
        $publicKey = (string) ($config['public_key'] ?? '');
        $publicKey = str_replace(["\r\n", "\r", "\n"], '\\n', $publicKey);

        return $this->setMultiple([
            'DASHEN_MERCHANT_CODE' => $config['merchant_code'] ?? '',
            'DASHEN_MERCHANT_APP_ID' => $config['merchant_app_id'] ?? '',
            'DASHEN_FABRIC_APP_ID' => $config['fabric_app_id'] ?? '',
            'DASHEN_MINI_APP_CODE' => $config['mini_app_code'] ?? '',
            'DASHEN_SHORT_CODE' => $config['short_code'] ?? '',
            'DASHEN_APP_SECRET' => $config['app_secret'] ?? '',
            'DASHEN_PUBLIC_KEY' => $publicKey,
            'DASHEN_STAGE' => $config['stage'] ?? 'uat',
            'DASHEN_BASE_URL' => $config['base_url'] ?? '',
            'DASHEN_TOKEN_PATH' => $config['token_path'] ?? '',
            'DASHEN_ORDER_QUERY_PATH' => $config['order_query_path'] ?? '',
        ]);
    }

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


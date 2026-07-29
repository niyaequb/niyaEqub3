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
     * Get all Chapa-related env values
     */
    public function getChapaConfig(): array
    {
        return [
            'CHAPA_SECRET_KEY' => $this->get('CHAPA_SECRET_KEY', ''),
            'CHAPA_PUBLIC_KEY' => $this->get('CHAPA_PUBLIC_KEY', ''),
            'CHAPA_WEBHOOK_SECRET' => $this->get('CHAPA_WEBHOOK_SECRET', ''),
        ];
    }

    /**
     * Set Chapa configuration
     */
    public function setChapaConfig(array $config): bool
    {
        return $this->setMultiple([
            'CHAPA_SECRET_KEY' => $config['secret_key'] ?? '',
            'CHAPA_PUBLIC_KEY' => $config['public_key'] ?? '',
            'CHAPA_WEBHOOK_SECRET' => $config['webhook_secret'] ?? '',
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


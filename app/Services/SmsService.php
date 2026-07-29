<?php

namespace App\Services;

use App\Services\EnvService;
use App\Models\SmsLog;
use App\Models\Otp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    protected EnvService $envService;
    protected $apiUrl;
    protected $token;
    protected $identifierId;
    protected $senderName;
    protected $callbackUrl;
    protected $geezApiUrl;
    protected $geezToken;
    protected $geezShortCodeId;
    protected $otpTtlMinutes;
    protected $smsMode;
    protected $length; // OTP length
    protected $afroBaseUrl;

    public function __construct(EnvService $envService)
    {
        $this->envService = $envService;

        // Load AFRO SMS settings from .env
        $this->afroBaseUrl = $this->envService->get('AFRO_BASE_URL', 'https://api.afromessage.com/api');
        $this->apiUrl = $this->afroBaseUrl . '/send';
        $this->token = $this->envService->get('AFRO_API_KEY');
        $this->identifierId = $this->envService->get('AFRO_IDENTIFIER_ID');
        $this->senderName = $this->envService->get('AFRO_SENDER_NAME');
        $this->length = (int) $this->envService->get('AFRO_OPT_LENGTH', 4);
        $this->callbackUrl = $this->envService->get('AFRO_CALLBACK_URL', '');

        // Load GEEZ SMS settings from .env
        $geezBaseUrl = $this->envService->get('GEEZ_SMS_BASE_URL', '');
        $this->geezApiUrl = $geezBaseUrl ? $geezBaseUrl . '/api/v1/sms/send' : '';
        $this->geezToken = $this->envService->get('GEEZ_SMS_TOKEN');
        $this->geezShortCodeId = $this->envService->get('GEEZ_SMS_SHORTCODE_ID');
        $this->otpTtlMinutes = (int) $this->envService->get('OTP_TTL_MINUTES', 5);
        $this->smsMode = (int) $this->envService->get('SMS_MODE', 1);
    }

    /**
     * Send OTP using AfroMessage challenge endpoint
     */
    public function sendOtpAfro(string $to): array
    {
        if (!$this->token) {
            return [
                'status' => 'error',
                'message' => 'AfroMessage SMS configuration missing.',
            ];
        }

        $url = rtrim($this->afroBaseUrl) . '/challenge';

        $query = [
            'from' => $this->identifierId,
            'sender' => $this->senderName,
            'to' => $to,
            'len' => $this->length,
            'ttl' => $this->otpTtlMinutes * 60,
            'ttl' => $this->otpTtlMinutes * 60,
            // 'pr' => ' ',
            'sb' => 1,
            'sa' => 1,
            'ps' => 'is Your Verification Code . Please do not share it with anyone.',
        ];

        // $response = Http::withToken($this->token)->get($url, $query);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])
                ->timeout(60)
                ->get($url, $query);
        } catch (\Exception $e) {
            Log::error('AfroMessage OTP Send Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result = [
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
            $this->logSms($to, 'OTP Request', 'error', $result, null, 'afro');
            // return $result;
        }

        if ($response->failed()) {
            $result = [
                'status' => 'error',
                'message' => 'OTP send failed.',
                'data' => $response->json(),
            ];
            $this->logSms($to, 'OTP Request', 'error', $result, null, 'afro');
            return $result;
        }

        $res = $response->json();

        if (($res['acknowledge'] ?? '') !== 'success') {
            $result = [
                'status' => 'error',
                'message' => $res['response']['message'] ?? 'OTP send error',
                'data' => $res,
            ];
            $this->logSms($to, 'OTP Request', 'error', $result, null, 'afro');
            return $result;
        }

        // Success
        $result = [
            'status' => 'success',
            'message' => 'OTP sent successfully',
            'code' => $res['response']['code'],
            'verificationId' => $res['response']['verificationId'],
            'data' => $res,
        ];

        $this->logSms($to, 'OTP Request', 'success', $result, null, 'afro');
        return $result;
    }

    /**
     * Verify OTP from AfroMessage
     */
    public function verifyAfroOtp( $to, $verificationId, $code): array
    {
        if (!$this->token) {
            return [
                'status' => 'error',
                'message' => 'AfroMessage SMS configuration missing.',
            ];
        }

        $url = rtrim($this->afroBaseUrl) . '/verify';

        if (!$to && !$verificationId) {
            return [
                'status' => 'error',
                'message' => 'Phone number or verification ID is required.',
            ];
        }

        $query = [
            'to' => $to,
            'vc' => $verificationId,
            'code' => $code,
        ];

        // $response = Http::withToken($this->token)->get($url, $query);
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->timeout(60)
            ->get($url, $query);

        if ($response->failed()) {
            return [
                'status' => 'error',
                'message' => 'Verification request failed.',
                'data' => $response->json(),
            ];
        }

        $res = $response->json();

        if (($res['acknowledge'] ?? '') !== 'success') {
            return [
                'status' => 'error',
                'message' => $res['response']['message'] ?? 'Invalid OTP',
                'data' => $res,
            ];
        }

        return [
            'status' => 'success',
            'message' => 'OTP verified successfully',
            'data' => $res['response'],
        ];
    }

    /**
     * Send SMS to a single recipient
     */
    public function sendSms(string $to, string $message, ?string $templateId = null, $sendable = null): array
    {
        if (!$this->token && !$this->geezToken) {
            $result = [
                'status' => 'error',
                'message' => 'SMS configuration is not active.',
            ];

            $this->logSms($to, $message, 'error', $result, $sendable);
            return $result;
        }

        $provider = $this->getActiveProvider();
        $response = null;

        if ($this->smsMode == '1') {
            $response = $this->afroSendSingleSms($to, $message);

            if ($this->isSuccess($response)) {
                $result = [
                    'status' => 'success',
                    'message' => 'SMS sent successfully',
                    'data' => $response,
                ];
                $this->logSms($to, $message, 'success', $result, $sendable, $provider);
                return $result;
            } else {
                $result = [
                    'status' => 'error',
                    'message' => $response['message'] ?? 'Failed to send SMS',
                    'data' => $response,
                ];
                $this->logSms($to, $message, 'error', $result, $sendable, $provider);
                return $result;
            }
        } elseif ($this->smsMode == '2') {
            $response = $this->geezSendSingleSms($to, $message);

            if (isset($response['error']) && $response['error'] == false) {
                $result = [
                    'status' => 'success',
                    'message' => 'SMS sent successfully',
                    'data' => $response,
                ];
                $this->logSms($to, $message, 'success', $result, $sendable, $provider);
                return $result;
            } else {
                $result = [
                    'status' => 'error',
                    'message' => $response['message'] ?? 'Failed to send SMS',
                    'data' => $response,
                ];
                $this->logSms($to, $message, 'error', $result, $sendable, $provider);
                return $result;
            }
        }

        $result = [
            'status' => 'error',
            'message' => 'Invalid SMS mode configuration.',
        ];
        $this->logSms($to, $message, 'error', $result, $sendable);
        return $result;
    }

    /**
     * Send OTP using Geez SMS
     */
    public function sendOtpGeez(string $to): array
    {
        if (!$this->geezToken) {
            return [
                'status' => 'error',
                'message' => 'Geez SMS configuration missing.',
            ];
        }

        $code = str_pad(rand(0, pow(10, $this->length) - 1), $this->length, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes($this->otpTtlMinutes);

        try {
            // Invalidate existing OTPs for this phone
            Otp::where('phone', $to)->whereNull('verified_at')->update(['verified_at' => now()]);

            Otp::create([
                'phone' => $to,
                'code' => $code,
                'expires_at' => $expiresAt,
                'provider' => 'geez',
            ]);

            $message = "Your Verification Code is {$code}. Please do not share it with anyone.";
            $response = $this->geezSendSingleSms($to, $message);

            if (isset($response['error']) && $response['error'] == false) {
                $result = [
                    'status' => 'success',
                    'message' => 'OTP sent successfully',
                    'code' => $code, // Return for consistency with Afro, though not strictly needed for client
                    'verificationId' => Str::random(13), // Generate a random verification ID for tracking
                    'data' => $response,
                ];
                $this->logSms($to, 'OTP Request (Geez)', 'success', $result, null, 'geez');
                return $result;
            }

            $result = [
                'status' => 'error',
                'message' => $response['message'] ?? 'OTP send failed via Geez',
                'data' => $response,
            ];
            $this->logSms($to, 'OTP Request (Geez)', 'error', $result, null, 'geez');
            return $result;
        } catch (\Exception $e) {
            Log::error('Geez OTP Send Exception', [
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify OTP from Geez SMS (Database)
     */
    public function verifyGeezOtp(string $to, string $code): array
    {
        $otp = Otp::where('phone', $to)
            ->where('code', $code)
            ->valid()
            ->first();

        if (!$otp) {
            return [
                'status' => 'error',
                'message' => 'Invalid or expired OTP.',
            ];
        }

        $otp->update(['verified_at' => now()]);

        return [
            'status' => 'success',
            'message' => 'OTP verified successfully',
        ];
    }

    /**
     * Send bulk SMS to multiple recipients
     */
    public function sendBulkSms(array $recipients, string $message): array
    {
        if (!$this->token && !$this->geezToken) {
            return [
                'status' => 'error',
                'message' => 'SMS configuration is not active.',
            ];
        }

        $responses = [];
        foreach ($recipients as $recipient) {
            $phone = $this->formatPhoneNumber($recipient);
            $responses[] = $this->sendSms($phone, $message);
        }

        return [
            'status' => 'success',
            'message' => 'Bulk SMS processing completed',
            'responses' => $responses,
        ];
    }

    /**
     * Send OTP SMS
     */
    public function sendOtp(string $to, string $otp): array
    {
        $provider = $this->getActiveProvider();

        if ($provider === 'AFRO') {
            return $this->sendOtpAfro($to);
        } elseif ($provider === 'GEEZ') {
            return $this->sendOtpGeez($to);
        }

        return [
            'status' => 'error',
            'message' => 'No active SMS provider configured for OTP.',
        ];
    }

    /**
     * Check if SMS response is successful
     */
    private function isSuccess($response): bool
    {
        if (is_string($response) && strpos($response, 'Error') === false) {
            return true;
        }
        if (is_array($response) && isset($response['acknowledge']) && $response['acknowledge'] == 'success') {
            return true;
        }
        if (is_array($response) && isset($response['status']) && $response['status'] == 'success') {
            return true;
        }
        return false;
    }

    /**
     * Send SMS using AFRO provider
     */
    private function afroSendSingleSms(string $to, string $message): array
    {
        if (!$this->token || !$this->apiUrl) {
            return [
                'status' => 'error',
                'message' => 'AFRO SMS configuration is incomplete.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])
                ->timeout(60)
                ->post($this->apiUrl, [
                    'from' => $this->identifierId,
                    'sender' => $this->senderName,
                    'to' => $to,
                    'message' => $message,
                    'callback' => $this->callbackUrl,
                ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('AFRO SMS API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'status' => 'error',
                    'message' => 'HTTP Error: ' . $response->status(),
                    'body' => $response->body(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('AFRO SMS Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send SMS using GEEZ provider
     */
    private function geezSendSingleSms(string $to, string $message): array
    {
        if (!$this->geezToken || !$this->geezApiUrl) {
            return [
                'status' => 'error',
                'message' => 'GEEZ SMS configuration is incomplete.',
            ];
        }

        try {
            $response = Http::timeout(60)
                ->asForm()
                ->post($this->geezApiUrl, [
                    'token' => $this->geezToken,
                    'phone' => $to,
                    'msg' => $message,
                    'shortcode_id' => $this->geezShortCodeId,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('GEEZ SMS API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'status' => 'error',
                'message' => 'HTTP Error: ' . $response->status(),
                'body' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('GEEZ SMS Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Format phone number to international format
     */
    public function formatPhoneNumber(string $number, string $countryCode = '251'): string
    {
        // Remove all non-digit characters
        $number = preg_replace('/\D/', '', $number);

        // Remove leading zero
        $number = ltrim($number, '0');

        // Add country code if not present
        if (strpos($number, $countryCode) !== 0) {
            $number = $countryCode . $number;
        }

        return '+' . $number;
    }

    /**
     * Get SMS configuration status
     */
    public function isConfigured(): bool
    {
        if ($this->smsMode == '1') {
            return !empty($this->token) && !empty($this->apiUrl) && !empty($this->identifierId);
        } elseif ($this->smsMode == '2') {
            return !empty($this->geezToken) && !empty($this->geezApiUrl) && !empty($this->geezShortCodeId);
        }

        return false;
    }

    /**
     * Get active provider name
     */
    public function getActiveProvider(): string
    {
        if ($this->smsMode == '1') {
            return 'AFRO';
        } elseif ($this->smsMode == '2') {
            return 'GEEZ';
        }

        return 'None';
    }

    /**
     * Log SMS message
     */
    protected function logSms(string $phone, string $message, string $status, array $response, $sendable = null, ?string $provider = null): void
    {
        try {
            $logData = [
                'phone' => $phone,
                'message' => $message,
                'status' => $status,
                'response' => json_encode($response),
                'provider' => $provider ?? $this->getActiveProvider(),
            ];

            if (isset($response['data']['reference'])) {
                $logData['reference'] = $response['data']['reference'];
            } elseif (isset($response['data']['id'])) {
                $logData['reference'] = $response['data']['id'];
            }

            if ($sendable) {
                $logData['sendable_type'] = get_class($sendable);
                $logData['sendable_id'] = $sendable->id;
            }

            SmsLog::create($logData);
        } catch (\Exception $e) {
            Log::error('Failed to log SMS', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
        }
    }
}

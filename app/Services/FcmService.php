<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Topic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FcmService
{
    protected $messaging;
    private $accessToken;
    private $projectId;
    private $fcmUrl;
    public function __construct()
    {
        $credentialsPath = config('services.firebase.credentials');
        $projectId = config('services.firebase.project_id') ?? env('FIREBASE_PROJECT_ID');

        $this->projectId = $projectId;
        $this->fcmUrl = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $this->accessToken = $this->getAccessToken();

        // Initialize Firebase Factory
        $factory = new Factory();

        // If a project ID is explicitly provided, use it
        if ($projectId) {
            $factory = $factory->withProjectId($projectId);
        }

        // Helper to get absolute path if relative
        $getAbsPath = function($path) {
            if (!$path) return null;
            if (str_starts_with($path, '/') || (strlen($path) > 2 && $path[1] === ':')) {
                return $path;
            }
            return base_path($path);
        };

        // Try to load service account credentials with absolute path conversion
        $absCredentialsPath = $getAbsPath($credentialsPath);
        if ($absCredentialsPath && file_exists($absCredentialsPath)) {
            $factory = $factory->withServiceAccount($absCredentialsPath);
        } else {
            // Fallback to environment variable if path not in config or file missing
            $envCredentials = $getAbsPath(env('FIREBASE_CREDENTIALS'));
            if ($envCredentials && file_exists($envCredentials)) {
                $factory = $factory->withServiceAccount($envCredentials);
            }
        }

        try {
            // The factory will try to auto-discover if not explicitly set
            $this->messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::error('[FCM] Failed to initialize Firebase Messaging. Ensure FIREBASE_CREDENTIALS or FIREBASE_PROJECT_ID is set correctly.', [
                'error' => $e->getMessage(),
                'path_attempted' => $absCredentialsPath ?? 'none'
            ]);
        }
    }

        private function getServiceAccountFromFile()
    {
        try {
            $serviceAccountPath = storage_path('app/firebase/service-account.json');

            if (!$serviceAccountPath) {
                return null;
            }

            // Check if file exists
            if (!file_exists($serviceAccountPath)) {
                Log::warning("Firebase service account file not found: {$serviceAccountPath}");
                return null;
            }

            // Read and parse JSON file
            $jsonContent = file_get_contents($serviceAccountPath);
            $serviceAccount = json_decode($jsonContent, true);

            if (!$serviceAccount) {
                throw new \Exception('Invalid service account JSON file format');
            }

            // Validate required fields
            $requiredFields = ['type', 'project_id', 'private_key', 'client_email'];
            foreach ($requiredFields as $field) {
                if (!isset($serviceAccount[$field])) {
                    throw new \Exception("Missing required field in service account: {$field}");
                }
            }

            // Log::info('Firebase service account loaded from file: ' . $serviceAccountPath);
            return $serviceAccount;
        } catch (\Exception $e) {
            Log::error('Failed to load service account from file: ' . $e->getMessage());
            return null;
        }
    }


      private function getAccessToken()
    {
        try {
            $useHttpV1 = config('services.firebase.use_http_v1', true);

            if (!$useHttpV1) {
                // Fallback to legacy API
                Log::info('Falling back to legacy FCM API');
                return config('services.firebase.server_key');
            }

            // Try to get service account from JSON file first
            $serviceAccount = $this->getServiceAccountFromFile();

            if (!$serviceAccount) {
                // Fallback to environment variable
                $serviceAccountKey = config('services.firebase.service_account_key');

                if (!$serviceAccountKey) {
                    Log::warning('Firebase service account key not configured, falling back to server key');
                    return config('services.firebase.server_key');
                }

                // Parse service account key from environment
                $serviceAccount = json_decode($serviceAccountKey, true);

                if (!$serviceAccount) {
                    throw new \Exception('Invalid service account key format');
                }
            }

            // Create credentials
            $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/firebase.messaging', $serviceAccount);

            // Get access token
            $token = $credentials->fetchAuthToken();

            if (!isset($token['access_token'])) {
                throw new \Exception('Failed to obtain access token');
            }



            // Log::info('Firebase access token obtained' . $token['access_token']);
            return $token['access_token'];
        } catch (\Exception $e) {
            Log::error('Failed to get Firebase access token: ' . $e->getMessage());

            // Fallback to server key for legacy API
            Log::info('Falling back to legacy FCM API');
            return config('services.firebase.server_key');
        }
    }
    /**
     * Build the FCM topic name for an Equb group.
     * e.g.  equb_group_42
     */
    public static function equbGroupTopic(int $equbGroupId): string
    {
        return "equb_group_{$equbGroupId}";
    }

    /**
     * Subscribe a single device token to a topic.
     * Called when a member joins an Equb group.
     */
    public function subscribeToTopic(string $fcmToken, string $topic): bool
    {
        if (!$this->messaging || !$fcmToken) {
            Log::warning('[FCM] subscribeToTopic skipped: messaging not initialized or token missing');
            return false;
        }

        try {
            $this->messaging->subscribeToTopic($topic, $fcmToken);
            Log::info("[FCM] subscribeToTopic: subscribed to {$topic}", ['token_prefix' => substr($fcmToken, 0, 10)]);
            return true;
        } catch (\Throwable $e) {
            Log::error("[FCM] subscribeToTopic failed: {$topic}", [
                'error' => $e->getMessage(),
                'token' => substr($fcmToken, 0, 10) . '...'
            ]);
        }

        return false;
    }

    /**
     * Unsubscribe a device token from a topic.
     * Called when a member leaves / membership becomes inactive.
     */
    public function unsubscribeFromTopic(string $fcmToken, string $topic): bool
    {
        if (!$this->messaging || !$fcmToken) {
            return false;
        }

        try {
            $this->messaging->unsubscribeFromTopic($topic, $fcmToken);
            return true;
        } catch (\Throwable $e) {
            Log::error("[FCM] unsubscribeFromTopic failed: {$topic}", ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Send a data-only push message to a topic using FCM V1.
     *
     * @param  string  $topic Topic name (without /topics/ prefix as SDK handles it)
     * @param  array  $data  Key-value pairs sent to the app
     */
    public function sendToTopic(string $topic, array $data, ?string $notificationTitle = null, ?string $notificationBody = null): bool
    {
        if (!$this->messaging) {
            Log::warning('[FCM] sendToTopic skipped: messaging not initialized');
            return false;
        }

        $message = CloudMessage::withTarget('topic', $topic)
            ->withData($data);

        if ($notificationTitle) {
            $message = $message->withNotification([
                'title' => $notificationTitle,
                'body' => $notificationBody ?? '',
            ]);
        }

        $notification = [
                'title' => $notificationTitle ?? '',
                'body' => $notificationBody ?? '',
            ];

         $payload = [
            'message' => [
                'topic' => $topic,
                'notification' => $notification,
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'high_importance_channel',
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'content-available' => 1,
                        ],
                    ],
                ],
            ],
        ];

        try {
            // $this->messaging->send($message);

             $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ])->post($this->fcmUrl, $payload);

            $result = $response->json();
                if ($response->failed()) {
                    Log::error("[FCM] sendToTopic HTTP request failed: {$topic}", [
                        'status' => $response->status(),
                        'response' => $result,
                        'payload' => $payload
                    ]);
                } else {
                    Log::info("[FCM] sendToTopic: message sent to topic {$topic}", [
                        'response' => $result,
                        'payload' => $payload
                     ]);
                }
            Log::info("[FCM] sendToTopic: message sent to topic {$topic}", ['data' => $data]);
            return true;
        } catch (\Throwable $e) {
            Log::error("[FCM] sendToTopic failed: {$topic}", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }

        return false;
    }

    /**
     * Send a notification to a specific device token using FCM V1.
     */
    public function sendToToken(string $fcmToken, array $data, ?string $notificationTitle = null, ?string $notificationBody = null): bool
    {
        if (!$this->messaging || !$fcmToken) {
            Log::warning('[FCM] sendToToken skipped: messaging not initialized or token missing');
            return false;
        }

        $message = CloudMessage::withTarget('token', $fcmToken)
            ->withData($data);

        if ($notificationTitle) {
            $message = $message->withNotification([
                'title' => $notificationTitle,
                'body' => $notificationBody ?? '',
            ])
            ->withAndroidConfig(\Kreait\Firebase\Messaging\AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'high_importance_channel',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]))
            ->withApnsConfig(\Kreait\Firebase\Messaging\ApnsConfig::fromArray([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'content-available' => 1,
                    ],
                ],
            ]));
        }

        try {
            $this->messaging->send($message);
            Log::info("[FCM] sendToToken: message sent to token", ['data' => $data, 'token' => substr($fcmToken, 0, 10) . '...']);
            return true;
        } catch (\Throwable $e) {
            Log::error("[FCM] sendToToken failed", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }

        return false;
    }
    /**
     * Send a notification to a specific user by their ID.
     */
    public function sendToUser(int $userId, array $data, ?string $notificationTitle = null, ?string $notificationBody = null): bool
    {
        $user = \App\Models\User::find($userId);
        if (!$user || !$user->fcm_token) {
            Log::warning("[FCM] sendToUser skipped: user not found or token missing", ['user_id' => $userId]);
            return false;
        }

        return $this->sendToToken($user->fcm_token, $data, $notificationTitle, $notificationBody);
    }
}

<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Donation;
use App\Models\EqubPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChapaService
{
    protected EnvService $envService;

    public function __construct(EnvService $envService)
    {
        $this->envService = $envService;
    }

    //  private function verifyWebhookSignature($request): bool
    // {
    //     $secret = env('CHAPA_WEBHOOK_SECRET');
    //     $signature = $request->header('chapa-signature');
    //     $payloadSignature = $request->header('x-chapa-signature');
    //     $payload = $request->getContent();

    //     if (!hash_equals(hash_hmac('sha256', $payload, $secret), $payloadSignature)) {
    //         Log::warning('Invalid webhook signature', [
    //             'received_payloadSignature' => $payloadSignature,
    //             'expected_payloadSignature' => hash_hmac('sha256', $payload, $secret),
    //             'received_signature' => $signature,
    //             'expected_signature' => hash_hmac('sha256', $payload, $secret),
    //             'payload' => $payload
    //         ]);
    //         return false;
    //     }

    //     return true;
    // }

    public function verifyWebhookSignature($request)
    {
        $webhookSignature = $request->header('x-chapa-signature'); // Retrieve the Chapa provided signature
        $secret = env('CHAPA_WEBHOOK_SECRET'); // Your secret key used for hashing

        // Calculate the expected signature using the secret key and the request's content
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        // Log the signatures for debugging
        Log::info('Verifying Chapa webhook signature', [
            'received_signature' => $webhookSignature,
            'expected_signature' => $expectedSignature,
        ]);

        // Compare the two signatures to verify the webhook
        return hash_equals($webhookSignature, $expectedSignature);
    }

    /**
     * Initialize payment with Chapa
     */
    public function initializePayment(Contribution $contribution, string $context = 'admin'): array
    {
        $secretKey = $this->envService->get('CHAPA_SECRET_KEY');
        if (! $secretKey) {
            throw new \Exception('Chapa secret key not configured. Please configure it in Settings.');
        }

        $member = $contribution->member;
        $webhookUrl = route('api.payment.chapa.webhook');

        if ($context === 'frontend') {
            $returnUrl = route('donations.contribution_return', ['reference' => $contribution->reference]);
        } else {
            $returnUrl = route('payment.chapa.return', ['reference' => $contribution->reference]);
        }

        $payload = [
            'amount' => $contribution->amount,
            'currency' => 'ETB',
            'email' => $member->email ?? 'member@gdca.com',
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'tx_ref' => $contribution->reference,
            'callback_url' => $webhookUrl, // Chapa uses callback_url for webhook
            'return_url' => $returnUrl,
            'customization' => [
                'title' => 'GDCA Cont. Pyt',
                'description' => $contribution->type->name ?? 'Contribution Payment',
            ],
            'meta' => [
                'member_id' => $member->id,
                'member_code' => $member->member_id,
                'contribution_id' => $contribution->id,
                'type' => $contribution->type->name ?? 'Contribution',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.chapa.co/v1/transaction/initialize', $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'checkout_url' => $data['data']['checkout_url'],
                    'reference' => $contribution->reference,
                ];
            }

            Log::info('Chapa initialization failed'.json_encode($data));

            // throw new \Exception($data['message'] ?? 'Failed to initialize payment');
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment',
            ];
        } catch (\Exception $e) {
            Log::error('Chapa payment initialization failed', [
                'contribution_id' => $contribution->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify payment with Chapa
     */
    public function verifyPayment(string $reference): array
    {
        $secretKey = $this->envService->get('CHAPA_SECRET_KEY');
        if (! $secretKey) {
            throw new \Exception('Chapa secret key not configured. Please configure it in Settings.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
            ])->get("https://api.chapa.co/v1/transaction/verify/{$reference}");

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'data' => $data['data'],
                    'message' => $data['message'] ?? 'Payment verified successfully',
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Payment verification failed',
                'data' => $data['data'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Chapa payment verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle Chapa webhook
     * Based on Chapa Laravel SDK documentation: https://developer.chapa.co/laravel-sdk
     */
    public function handleWebhook(array $payload, ?string $signature = null): array
    {
        // Verify webhook signature if webhook_secret is configured
        $webhookSecret = $this->envService->get('CHAPA_WEBHOOK_SECRET');
        if ($webhookSecret && $signature) {
            $expectedSignature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
            if (! hash_equals($expectedSignature, $signature)) {
                Log::warning('Chapa webhook signature verification failed', [
                    'payload' => $payload,
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid webhook signature',
                ];
            }
        }

        $reference = $payload['tx_ref'] ?? ($payload['data']['tx_ref'] ?? null);

        if (! $reference) {
            Log::error('Chapa webhook: Reference not found', [
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'message' => 'Reference not found in webhook',
            ];
        }

        $contribution = Contribution::where('reference', $reference)->first();

        if (! $contribution) {
            Log::error('Chapa webhook: Contribution not found', [
                'reference' => $reference,
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'message' => 'Contribution not found',
            ];
        }

        // Find or create transaction log (should already exist from when contribution was created)
        $transactionLog = $contribution->transactionLogs()
            ->whereNull('processed_at')
            ->latest()
            ->first();

        if (! $transactionLog) {
            // Create if doesn't exist (fallback for edge cases)
            $transactionLog = $contribution->transactionLogs()->create([
                'raw_payload' => $payload,
                'status_code' => ($payload['status'] ?? ($payload['data']['status'] ?? null)) === 'success' ? 200 : 400,
                'status_message' => $payload['status'] ?? ($payload['data']['status'] ?? 'unknown'),
            ]);
        } else {
            // Update existing transaction log with webhook payload
            $transactionLog->update([
                'raw_payload' => $payload,
                'status_code' => ($payload['status'] ?? ($payload['data']['status'] ?? null)) === 'success' ? 200 : 400,
                'status_message' => $payload['status'] ?? ($payload['data']['status'] ?? 'unknown'),
            ]);
        }

        // Verify payment with Chapa API (always verify webhook data)
        $verification = $this->verifyPayment($reference);

        if ($verification['success']) {
            // Only update if still pending
            if ($contribution->isPending()) {
                $contribution->markAsPaid([
                    'chapa_response' => $verification['data'],
                    'webhook_payload' => $payload,
                    'verified_at' => now()->toDateTimeString(),
                ]);
            }

            $transactionLog->update([
                'status_code' => 200,
                'status_message' => 'success',
                'processed_at' => now(),
            ]);

            Log::info('Chapa webhook processed successfully', [
                'contribution_id' => $contribution->id,
                'reference' => $reference,
            ]);

            return [
                'success' => true,
                'contribution' => $contribution,
                'message' => 'Payment verified and processed',
            ];
        }

        // If verification fails but webhook says success, mark as failed
        if ($contribution->isPending()) {
            $contribution->markAsFailed([
                'verification_error' => $verification['message'],
                'webhook_payload' => $payload,
            ]);
        }

        $transactionLog->update([
            'status_code' => 400,
            'status_message' => 'failed',
            'processed_at' => now(),
        ]);

        Log::warning('Chapa webhook verification failed', [
            'contribution_id' => $contribution->id,
            'reference' => $reference,
            'verification_message' => $verification['message'],
        ]);

        return [
            'success' => false,
            'message' => $verification['message'],
        ];
    }

    /**
     * Initialize donation payment with Chapa
     */
    public function initializeDonationPayment(Donation $donation, string $context = 'admin'): array
    {
        $secretKey = $this->envService->get('CHAPA_SECRET_KEY');
        if (! $secretKey) {
            throw new \Exception('Chapa secret key not configured. Please configure it in Settings.');
        }

        $donor = $donation->donor;
        $campaign = $donation->campaign;
        $webhookUrl = route('api.payment.chapa.webhook');

        // Different return URLs for admin vs frontend
        if ($context === 'frontend') {
            $returnUrl = route('donations.return', ['reference' => $donation->reference]);
        } else {
            $returnUrl = route('payment.donation.return', ['reference' => $donation->reference]);
        }

        // Handle anonymous donations
        $isAnonymous = $donation->is_anonymous ?? false;
        $email = $isAnonymous ? 'anonymous@gmail.com' : $donor->email ?? 'donor@example.com';
        $firstName = $isAnonymous ? 'Anonymous' : $donor->name ?? 'Donor';
        $lastName = '';

        // Split name if it contains space
        if (! $isAnonymous && $donor && str_contains($donor->name, ' ')) {
            $nameParts = explode(' ', $donor->name, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
        }

        $payload = [
            'amount' => $donation->amount,
            'currency' => 'ETB',
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tx_ref' => $donation->reference,
            'callback_url' => $webhookUrl,
            'return_url' => $returnUrl,
            'customization' => [
                'title' => 'GDCA Donation',
                'description' => Str::limit(preg_replace('/[^A-Za-z0-9\-\_\.\s]/', '', $campaign?->name ?? 'General Donation'), 50, ''),
            ],
            'meta' => [
                'donation_id' => $donation->id,
                'donor_id' => $donor?->id,
                'campaign_id' => $campaign?->id,
                'type' => 'Donation',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.chapa.co/v1/transaction/initialize', $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'checkout_url' => $data['data']['checkout_url'],
                    'reference' => $donation->reference,
                ];
            }

            Log::info('Chapa donation initialization failed', ['data' => json_encode($data)]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment',
            ];
        } catch (\Exception $e) {
            Log::error('Chapa donation payment initialization failed', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle Chapa webhook for both contributions and donations
     */
    public function handleWebhookForDonation(array $payload, ?string $signature = null): array
    {
        // Verify webhook signature if webhook_secret is configured
        $webhookSecret = $this->envService->get('CHAPA_WEBHOOK_SECRET');
        if ($webhookSecret && $signature) {
            $expectedSignature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
            if (! hash_equals($expectedSignature, $signature)) {
                Log::warning('Chapa webhook signature verification failed', [
                    'payload' => $payload,
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid webhook signature',
                ];
            }
        }

        $reference = $payload['tx_ref'] ?? ($payload['data']['tx_ref'] ?? null);

        if (! $reference) {
            Log::error('Chapa webhook: Reference not found', [
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'message' => 'Reference not found in webhook',
            ];
        }

        // Try to find donation first (donations have GDCA-DON- prefix)
        $donation = Donation::where('reference', $reference)->first();

        if ($donation) {
            // Find or create transaction log (should already exist from when donation was created)
            $transactionLog = $donation->transactionLogs()
                ->whereNull('processed_at')
                ->latest()
                ->first();

            if (! $transactionLog) {
                // Create if doesn't exist (fallback for edge cases)
                $transactionLog = $donation->transactionLogs()->create([
                    'raw_payload' => $payload,
                    'status_code' => ($payload['status'] ?? ($payload['data']['status'] ?? null)) === 'success' ? 200 : 400,
                    'status_message' => $payload['status'] ?? ($payload['data']['status'] ?? 'unknown'),
                ]);
            } else {
                // Update existing transaction log with webhook payload
                $transactionLog->update([
                    'raw_payload' => $payload,
                    'status_code' => ($payload['status'] ?? ($payload['data']['status'] ?? null)) === 'success' ? 200 : 400,
                    'status_message' => $payload['status'] ?? ($payload['data']['status'] ?? 'unknown'),
                ]);
            }

            // Verify payment with Chapa API (always verify webhook data)
            $verification = $this->verifyPayment($reference);

            if ($verification['success']) {
                // Only update if still pending
                if ($donation->isPending()) {
                    $donation->markAsPaid([
                        'chapa_response' => $verification['data'],
                        'webhook_payload' => $payload,
                        'verified_at' => now()->toDateTimeString(),
                    ]);
                }

                $transactionLog->update([
                    'status_code' => 200,
                    'status_message' => 'success',
                    'processed_at' => now(),
                ]);

                Log::info('Chapa donation webhook processed successfully', [
                    'donation_id' => $donation->id,
                    'reference' => $reference,
                ]);

                return [
                    'success' => true,
                    'donation' => $donation,
                    'message' => 'Donation payment verified and processed',
                ];
            }

            // If verification fails but webhook says success, mark as failed
            if ($donation->isPending()) {
                $donation->markAsFailed([
                    'verification_error' => $verification['message'],
                    'webhook_payload' => $payload,
                ]);
            }

            $transactionLog->update([
                'status_code' => 400,
                'status_message' => 'failed',
                'processed_at' => now(),
            ]);

            Log::warning('Chapa donation webhook verification failed', [
                'donation_id' => $donation->id,
                'reference' => $reference,
                'verification_message' => $verification['message'],
            ]);

            return [
                'success' => false,
                'message' => $verification['message'],
            ];
        }

        // Try Equb payment by reference (EQUB- prefix)
        if (str_starts_with((string) $reference, 'EQUB-')) {
            return $this->handleWebhookForEqubPayment($payload, $signature);
        }

        // If not a donation or equb, fall back to contribution handling
        return $this->handleWebhook($payload, $signature);
    }

    /**
     * Initialize Equb payment with Chapa
     */
    public function initializeEqubPayment(EqubPayment $payment, string $context = 'admin'): array
    {
        $secretKey = $this->envService->get('CHAPA_SECRET_KEY');
        if (! $secretKey) {
            throw new \Exception('Chapa secret key not configured. Please configure it in Settings.');
        }

        $membership = $payment->membership;
        $member = $membership?->member;
        $user = $member?->user;
        $name = $member?->full_name ?? 'Member';
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $webhookUrl = route('payment.chapa.webhook');
        $returnUrl = config('app.url').'/admin/equb-payments?chapa_return='.$payment->reference;

        $payload = [
            'amount' => $payment->amount,
            'currency' => 'ETB',
            'email' => $user?->email ?? 'member@equb.com',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tx_ref' => $payment->reference,
            'callback_url' => $webhookUrl,
            'return_url' => $returnUrl,
            'customization' => [
                'title' => 'Equb Payment',
                'description' => 'Equb contribution payment',
            ],
            'meta' => [
                'equb_payment_id' => $payment->id,
                'equb_membership_id' => $payment->equb_membership_id,
                'type' => 'EqubPayment',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$secretKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.chapa.co/v1/transaction/initialize', $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'checkout_url' => $data['data']['checkout_url'],
                    'reference' => $payment->reference,
                ];
            }

            Log::info('Chapa Equb initialization failed', ['data' => $data]);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to initialize payment',
            ];
        } catch (\Exception $e) {
            Log::error('Chapa Equb payment initialization failed', [
                'equb_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle Chapa webhook for Equb payment
     */
    public function handleWebhookForEqubPayment(array $payload, ?string $signature = null): array
    {
        $webhookSecret = $this->envService->get('CHAPA_WEBHOOK_SECRET');
        if ($webhookSecret && $signature) {
            $expectedSignature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
            if (! hash_equals($expectedSignature, $signature)) {
                Log::warning('Chapa webhook signature verification failed', ['payload' => $payload]);

                return ['success' => false, 'message' => 'Invalid webhook signature'];
            }
        }

        $reference = $payload['tx_ref'] ?? ($payload['data']['tx_ref'] ?? null);
        if (! $reference) {
            Log::error('Chapa webhook: Reference not found', ['payload' => $payload]);

            return ['success' => false, 'message' => 'Reference not found in webhook'];
        }

        $payment = EqubPayment::where('reference', $reference)->first();
        if (! $payment) {
            Log::error('Chapa webhook: Equb payment not found', ['reference' => $reference]);

            return ['success' => false, 'message' => 'Equb payment not found'];
        }
                Log::info('pAYLOAD'.json_encode($payload));


        if($payload['event'] === 'charge.success'){
            $verification = $this->verifyPayment($reference);
            if ($verification['success']) {
                if ($payment->isPending()) {
                    $payment->markAsPaid();
                    
                    // Check for individual completion
                    if ($payment->membership) {
                        app(\App\Services\EqubMembershipService::class)->completeIfEligible($payment->membership);
                    }

                    app(\App\Services\SmsService::class)->sendSms(
                        $payment->membership?->member?->user?->phone ?? '',
                        'Your Equb payment of '.$payment->amount.' ETB has been received successfully.',
                        null,
                        $payment
                    );
                }
                Log::info('Chapa Equb webhook processed successfully', ['equb_payment_id' => $payment->id]);

                return ['success' => true, 'payment' => $payment, 'message' => 'Equb payment verified and processed'];
            }

        }


        if ($payment->isPending()) {
            $payment->markAsFailed();
        }
        Log::warning('Chapa Equb webhook verification failed', ['equb_payment_id' => $payment->id]);

        return ['success' => false, 'message' => $verification['message']];
    }
}

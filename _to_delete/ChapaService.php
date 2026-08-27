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

    /**
     * Make a string safe for Chapa's `customization` fields.
     *
     * Chapa rejects the entire transaction if customization.title or
     * customization.description contains anything outside letters, numbers,
     * hyphens, underscores, spaces and dots. "Letters" means ASCII letters, so
     * Amharic and Oromo text does not survive either.
     *
     * Every string that reaches these fields carries something a person typed
     * — a campaign name, a contribution type, the name a sponsor gave someone
     * under "My Responsibility People". Sanitising here, at the one point where
     * the value crosses into Chapa, is the only place it can be done reliably;
     * upstream callers are all free to hand over a real name and none of them
     * should have to know this rule.
     *
     * Disallowed characters become a space instead of being dropped, so
     * "Mohammed (Junior)" reads as "Mohammed Junior" and not "MohammedJunior".
     * When nothing usable survives — a name written entirely in Amharic — the
     * caller's fallback is used, because a cosmetic label must never be the
     * reason a payment is refused.
     */
    protected function chapaText(?string $value, string $fallback, int $limit = 50): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_. ]+/', ' ', (string) $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean), " .");

        if ($clean === '') {
            return $fallback;
        }

        return rtrim(mb_substr($clean, 0, $limit), ' .');
    }

    /**
     * Turn a Chapa failure into something worth showing a member.
     *
     * Chapa sends `message` as a plain string for ordinary failures, but as a
     * field => errors map when its own request validation rejects the payload.
     * Returning that map straight to the client is how a member ended up
     * reading "{customization.description: [The customization.description may
     * only contain letters, numbers...]}" across the bottom of the app — a
     * message about our request shape, addressed to nobody who could act on it.
     *
     * The full payload is already written to the log by every caller, which is
     * where that detail belongs.
     */
    protected function chapaFailureMessage(
        mixed $message,
        string $fallback = 'Payment could not be started. Please try again.',
    ): string {
        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return $fallback;
    }

    /**
     * "Equb contribution for Amina", or just "Equb contribution payment" when
     * the name cannot be carried.
     *
     * The name is cleaned by itself so the sentence is only built once there
     * is something left to put in it. Cleaning the whole sentence at once
     * leaves "Equb contribution for" trailing off when the name was written in
     * a script Chapa will not accept.
     */
    protected function chapaDescriptionFor(?string $name): string
    {
        $clean = $this->chapaText($name, '', 30);

        return $clean === ''
            ? 'Equb contribution payment'
            : 'Equb contribution for '.$clean;
    }

    /**
     * The single door out to Chapa.
     *
     * Every transaction in this class posts through here so the customization
     * fields are scrubbed one last time on the way out, whatever the caller
     * assembled. Chapa rejects the whole request — no charge, no checkout page,
     * an error the member sees — if title or description carries anything
     * outside letters, numbers, hyphens, underscores, spaces and dots.
     *
     * Callers already sanitise, so in practice this changes nothing. It exists
     * because that promise is easy to break: these strings are built from
     * campaign names, contribution types and names typed by members, and it
     * only takes one new call site interpolating a raw name to put a bracket
     * or an apostrophe back into the payload. A guarantee held in one place
     * that every request must pass through is worth more than the same rule
     * repeated at four call sites and remembered at a fifth.
     */
    protected function postToChapa(array $payload, string $secretKey)
    {
        if (isset($payload['customization']) && is_array($payload['customization'])) {
            $custom = $payload['customization'];

            $safeTitle = $this->chapaText($custom['title'] ?? null, 'Payment', 16);
            $safeDescription = $this->chapaText($custom['description'] ?? null, 'Payment');

            // If anything had to be changed here, a caller slipped through.
            // Log it rather than silently papering over it, so the real fix
            // can be made upstream instead of relying on this net forever.
            if (($custom['title'] ?? null) !== $safeTitle
                || ($custom['description'] ?? null) !== $safeDescription) {
                Log::warning('Chapa customization was rewritten before sending', [
                    'tx_ref' => $payload['tx_ref'] ?? null,
                    'title_before' => $custom['title'] ?? null,
                    'title_after' => $safeTitle,
                    'description_before' => $custom['description'] ?? null,
                    'description_after' => $safeDescription,
                ]);
            }

            $payload['customization']['title'] = $safeTitle;
            $payload['customization']['description'] = $safeDescription;
        }

        return Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.chapa.co/v1/transaction/initialize', $payload);
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
                // Contribution type names are admin-entered and have the same
                // exposure as any other free-text label reaching Chapa.
                'description' => $this->chapaText($contribution->type->name ?? null, 'Contribution Payment'),
            ],
            'meta' => [
                'member_id' => $member->id,
                'member_code' => $member->member_id,
                'contribution_id' => $contribution->id,
                'type' => $contribution->type->name ?? 'Contribution',
            ],
        ];

        try {
            $response = $this->postToChapa($payload, $secretKey);

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
                'description' => $this->chapaText($campaign?->name, 'General Donation'),
            ],
            'meta' => [
                'donation_id' => $donation->id,
                'donor_id' => $donor?->id,
                'campaign_id' => $campaign?->id,
                'type' => 'Donation',
            ],
        ];

        try {
            $response = $this->postToChapa($payload, $secretKey);

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

        // The billing identity is whoever is actually paying. On a place held
        // for someone with no Niya account ("My Responsibility People") that
        // is the sponsor — the seat itself has no member and no email, which
        // would otherwise send Chapa a nameless customer.
        $payer = $membership?->payerMember();
        $user = $payer?->user;
        $name = $payer?->full_name ?? 'Member';
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        $isSeat = (bool) $membership?->isResponsibilitySeat();

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
                // The name here is whatever the sponsor typed for a person
                // they are responsible for, so it is sanitised on its own
                // rather than as part of the sentence: a name written in
                // Amharic survives Chapa's ASCII-only rule as nothing at all,
                // and "Equb contribution for" with the name missing off the
                // end reads worse than not naming them.
                'description' => $isSeat
                    ? $this->chapaDescriptionFor($membership->displayName())
                    : 'Equb contribution payment',
            ],
            'meta' => [
                'equb_payment_id' => $payment->id,
                'equb_membership_id' => $payment->equb_membership_id,
                'type' => 'EqubPayment',
                'paid_by_member_id' => $payer?->id,
                'is_responsibility_seat' => $isSeat,
            ],
        ];

        try {
            $response = $this->postToChapa($payload, $secretKey);

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
                'message' => $this->chapaFailureMessage($data['message'] ?? null),
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
     * Initialize one Chapa transaction covering several Equb contributions.
     *
     * Used when a member settles their own place and the places they hold for
     * "My Responsibility People" together. Each contribution keeps its own
     * equb_payments row; they share $batchReference, which is what Chapa is
     * given as tx_ref and what the webhook resolves them by.
     *
     * @param  array<int, EqubPayment>  $payments
     */
    public function initializeEqubBatchPayment(
        array $payments,
        string $batchReference,
        float $total,
        $payer,
        string $context = 'frontend',
    ): array {
        $secretKey = $this->envService->get('CHAPA_SECRET_KEY');
        if (! $secretKey) {
            throw new \Exception('Chapa secret key not configured. Please configure it in Settings.');
        }

        $user = $payer?->user;
        $name = $payer?->full_name ?? 'Member';
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $count = count($payments);

        $webhookUrl = route('payment.chapa.webhook');
        $returnUrl = config('app.url').'/admin/equb-payments?chapa_return='.$batchReference;

        $payload = [
            'amount' => $total,
            'currency' => 'ETB',
            'email' => $user?->email ?? 'member@equb.com',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tx_ref' => $batchReference,
            'callback_url' => $webhookUrl,
            'return_url' => $returnUrl,
            'customization' => [
                'title' => 'Equb Payment',
                // No brackets: Chapa allows only letters, numbers, hyphens,
                // underscores, spaces and dots, and "(2 places)" was enough to
                // have it reject the payment outright.
                'description' => $count > 1
                    ? $this->chapaText(
                        "Equb contribution for {$count} places",
                        'Equb contribution payment',
                    )
                    : 'Equb contribution payment',
            ],
            'meta' => [
                'type' => 'EqubPaymentBatch',
                'batch_reference' => $batchReference,
                'equb_payment_ids' => implode(',', array_map(fn (EqubPayment $p): int => $p->id, $payments)),
                'paid_by_member_id' => $payer?->id,
                'contributions' => $count,
            ],
        ];

        try {
            $response = $this->postToChapa($payload, $secretKey);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                return [
                    'success' => true,
                    'checkout_url' => $data['data']['checkout_url'],
                    'reference' => $batchReference,
                ];
            }

            Log::info('Chapa Equb batch initialization failed', ['data' => $data]);

            return [
                'success' => false,
                'message' => $this->chapaFailureMessage($data['message'] ?? null),
            ];
        } catch (\Exception $e) {
            Log::error('Chapa Equb batch payment initialization failed', [
                'batch_reference' => $batchReference,
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

        // One reference can stand for one contribution or for a whole batch.
        //
        // A member holding places for other people settles the round in a
        // single charge; every equb_payments row in that charge carries the
        // same batch_reference. Resolving by reference first keeps the old
        // single-payment path byte-for-byte identical.
        $payments = EqubPayment::where('reference', $reference)->get();

        if ($payments->isEmpty()) {
            $payments = EqubPayment::where('batch_reference', $reference)->get();
        }

        if ($payments->isEmpty()) {
            Log::error('Chapa webhook: Equb payment not found', ['reference' => $reference]);

            return ['success' => false, 'message' => 'Equb payment not found'];
        }

        Log::info('Chapa Equb webhook payload: '.json_encode($payload));

        if (($payload['event'] ?? null) === 'charge.success') {
            $verification = $this->verifyPayment($reference);

            if ($verification['success']) {
                // Every contribution in the charge settles together. Marking
                // only one would leave the member's own place paid and the
                // places they pay for still showing as owed, after they had
                // already been charged for all of them.
                foreach ($payments as $payment) {
                    if (! $payment->isPending()) {
                        continue;
                    }

                    $payment->markAsPaid();

                    $membership = $payment->membership;

                    if ($membership) {
                        app(\App\Services\EqubMembershipService::class)->completeIfEligible($membership);
                    }
                }

                $this->announceEqubPaymentSettled($payments, $reference);

                Log::info('Chapa Equb webhook processed successfully', [
                    'reference' => $reference,
                    'payments' => $payments->pluck('id')->all(),
                ]);

                return [
                    'success' => true,
                    'payment' => $payments->first(),
                    'payments' => $payments,
                    'message' => 'Equb payment verified and processed',
                ];
            }

            foreach ($payments as $payment) {
                if ($payment->isPending()) {
                    $payment->markAsFailed();
                }
            }

            Log::warning('Chapa Equb webhook verification failed', [
                'reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
            ]);

            return ['success' => false, 'message' => $verification['message']];
        }

        foreach ($payments as $payment) {
            if ($payment->isPending()) {
                $payment->markAsFailed();
            }
        }

        Log::warning('Chapa Equb webhook: charge was not successful', [
            'reference' => $reference,
            'event' => $payload['event'] ?? null,
        ]);

        return ['success' => false, 'message' => 'The charge was not successful.'];
    }

    /**
     * One receipt per person, not per contribution.
     *
     * A member who just settled three places should get one message naming the
     * total, not three texts each quoting a third of what left their account.
     * Grouped by the payer's phone, since payerUser() resolves a held place to
     * the sponsor who actually paid for it.
     *
     * @param  \Illuminate\Support\Collection<int, EqubPayment>  $payments
     */
    protected function announceEqubPaymentSettled($payments, string $reference): void
    {
        $receipts = [];

        foreach ($payments as $payment) {
            $membership = $payment->membership;
            $phone = $membership?->payerUser()?->phone;

            if (! $phone) {
                continue;
            }

            $receipts[$phone] ??= ['total' => 0.0, 'held' => []];
            $receipts[$phone]['total'] += (float) $payment->amount;

            if ($membership->isResponsibilitySeat()) {
                $receipts[$phone]['held'][] = $membership->displayName();
            }
        }

        foreach ($receipts as $phone => $receipt) {
            $held = $receipt['held'] !== []
                ? ' This covers '.implode(', ', $receipt['held']).' as well as your own place.'
                : '';

            try {
                app(\App\Services\SmsService::class)->sendSms(
                    $phone,
                    'Your Equb payment of '.number_format($receipt['total'], 2)
                        .' ETB has been received successfully.'.$held,
                    null,
                    null
                );
            } catch (\Throwable $e) {
                // A dead SMS gateway must never undo a settled payment.
                Log::warning('Equb payment receipt SMS failed: '.$e->getMessage(), [
                    'reference' => $reference,
                ]);
            }
        }
    }
}

<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayOrder;
use App\Services\EnvService;

/**
 * Commercial Bank of Ethiopia — CBE Mobile Banking, "Unified Outgoing" API.
 *
 * THE DIRECTION IS REVERSED
 *
 * Every other bank here is called BY us. CBE calls US: the member types their
 * 10-digit Niya reference into CBE Mobile Banking, CBE asks /api/cbe/Query
 * what is owed, debits the customer, and tells /api/cbe/payment it is done.
 * Those calls arrive through the IPSec tunnel from CBE's hosts and are handled
 * by CbeController.
 *
 * So this gateway never contacts the bank. createOrder() records that a
 * reference is open for payment, and verifyPayment() reads what CbeController
 * recorded when CBE confirmed the debit. That is what lets CBE settle through
 * PaymentSettlementService exactly like every other bank — same lock, same
 * crediting, same receipt SMS, same commission — rather than through a second
 * path written for one bank.
 *
 * The row in payment_gateway_orders is the record of CBE's confirmation:
 * status 'confirmed', external_order_id = Cbe_Txn_Ref, status_response = what
 * CBE sent plus our Destination_txn_Ref.
 */
class CbeGateway implements PaymentGateway
{
    public const STATUS_OPEN = 'awaiting_payment';

    public const STATUS_CONFIRMED = 'confirmed';

    protected EnvService $env;

    protected array $config;

    public function __construct(EnvService $env, array $config = [])
    {
        $this->env = $env;
        $this->config = $config;
    }

    public function slug(): string
    {
        return 'cbe';
    }

    public function displayName(): string
    {
        return $this->config['name'] ?? 'CBE Mobile Banking';
    }

    protected function envPrefix(): string
    {
        return $this->config['env_prefix'] ?? 'CBE';
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $value = $this->env->get($this->envPrefix().'_'.$key, $default);

        return $value === null ? $default : trim((string) $value);
    }

    public function clientId(): string
    {
        return (string) $this->setting('CLIENT_ID', '');
    }

    public function clientSecret(): string
    {
        return (string) $this->setting('CLIENT_SECRET', '');
    }

    /** Niya's collection account at CBE, returned by Query and checked on Payment. */
    public function creditAccount(): string
    {
        return (string) $this->setting('CREDIT_ACCOUNT', '1000800212522');
    }

    /** Niya's TIN, returned by Query and checked on Payment. */
    public function tin(): string
    {
        return (string) $this->setting('TIN', '0105183255');
    }

    /** CBE hosts allowed to call /api/cbe/*. Checked against the TCP peer, never a header. */
    public function allowedIps(): array
    {
        $raw = (string) $this->setting('ALLOWED_IPS', '172.30.113.10,172.30.113.11,172.30.113.12,127.0.0.1');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->creditAccount() !== '';
    }

    public function canVerifySettlement(): bool
    {
        return $this->isConfigured();
    }

    /**
     * Open a reference for payment. Nothing is sent to CBE: the member takes
     * the reference to CBE Mobile Banking themselves.
     */
    public function createOrder(string $reference, float $amount, string $narration): array
    {
        $amount = round($amount, 2);

        PaymentGatewayOrder::updateOrCreate(
            ['gateway' => $this->slug(), 'reference' => $reference],
            [
                'amount_minor' => (int) round($amount * 100),
                'currency' => 'ETB',
                'status' => self::STATUS_OPEN,
                'register_response' => ['narration' => $narration, 'e2e' => []],
            ]
        );

        return [
            'type' => 'bill_reference',
            'reference' => $reference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'ETB',
            'expires_in_seconds' => 24 * 3600,
            'message' => 'Open CBE Mobile Banking, choose Niya Equb, and enter reference '
                .$reference.' to pay '.number_format($amount, 2).' ETB.',
        ];
    }

    public function authPayload(?string $customerIdentifier = null): array
    {
        return [];
    }

    /**
     * Has CBE confirmed this reference? Answered from our own record of CBE's
     * Payment call — never from the member's app.
     *
     * Not confirmed is "pending + not_found", which PaymentSettlementService
     * leaves pending and writes off only once the order is older than its
     * abandonment window.
     */
    public function verifyPayment(string $reference): array
    {
        $order = PaymentGatewayOrder::query()
            ->where('gateway', $this->slug())
            ->where('reference', $reference)
            ->first();

        if ($order && $order->status === self::STATUS_CONFIRMED) {
            return [
                'success' => true,
                'message' => 'Payment confirmed by CBE',
                'data' => (array) ($order->status_response ?? []),
            ];
        }

        return [
            'success' => false,
            'pending' => true,
            'not_found' => true,
            'answered' => true,
            'message' => 'CBE has not confirmed a payment for this reference.',
        ];
    }

    /** CBE sends no unprompted notifications; its Payment call is authenticated by token and tunnel. */
    public function verifyNotificationSignature(string $rawBody, ?string $signature): bool
    {
        return false;
    }

    public function signatureHeaders(): array
    {
        return [];
    }

    public function extractReference(array $payload): ?string
    {
        return null;
    }

    public function extractSettlement(array $data): array
    {
        $request = (array) ($data['request'] ?? []);

        return array_filter([
            'transaction_id' => $request['Cbe_Txn_Ref'] ?? null,
            'bank_reference' => $request['End_To_End_Txn_Id'] ?? null,
            'paid_at' => $data['confirmed_at'] ?? now(),
            'amount' => $request['Amount'] ?? null,
            'payer_name' => $request['Full_Name'] ?? null,
            'payer_phone' => $request['Phone_No'] ?? null,
            'payload' => $data,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function exchangeCustomerIdentifier(string $identifier): array
    {
        return ['success' => false, 'unsupported' => true];
    }

    public function clientConfig(): array
    {
        $client = $this->config['client'] ?? [];

        return [
            'slug' => $this->slug(),
            'name' => $this->displayName(),
            // Show order_payload.reference and .message, then poll the contribution.
            'kind' => $client['kind'] ?? 'bill_reference',
            'bridge' => null,
            'app_code' => null,
            'stage' => 'production',
            'supports_identity' => false,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\EqubPayment;
use App\Models\PaymentGatewayOrder;
use App\Services\Payments\Gateways\CbeGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CBE Mobile Banking → Niya: the "Unified Outgoing" destination API.
 *
 *   POST /api/cbe/token    client_credentials → bearer token (3600 s)
 *   POST /api/cbe/Query    what is owed on a Bill_Id
 *   POST /api/cbe/payment  CBE debited the customer; credit the contributions
 *
 * Every rule here was agreed with CBE's QA during SIT on the test environment
 * and must not drift from it:
 *   - Destination_Api_Name is echoed, never validated.
 *   - Bill_Id is our 10-digit reference (see NumericReference).
 *   - A Payment's End_To_End_Txn_Id must be one used in a successful Query
 *     for the SAME Bill_Id, and an End_To_End_Txn_Id belongs to one bill only.
 *   - A repeated Cbe_Txn_Ref is FAILED, code 2, carrying the original
 *     Destination_txn_Ref; the same Cbe_Txn_Ref on another bill is code 3.
 *   - Full_Name is mandatory on Payment; Timestamp, when sent, is ISO 8601;
 *     Additional_Fields, when sent, is an array of Key/Value objects;
 *     Credit_Acct_Number and Tin_Number, when sent, equal what Query returned.
 *   - The response field is spelled Destination_txn_Ref (lowercase t), as in
 *     CBE's sample — their gateway maps that exact name.
 *   - Response_Code: 0 success, 1 not found, 2 paid/expired/duplicate,
 *     3 validation, 99 system. HTTP 401 for a bad token, 400 invalid_client.
 *
 * Reachable only from CBE's hosts through the tunnel. The caller is checked
 * against REMOTE_ADDR, not request()->ip(): this application trusts
 * X-Forwarded-For from any proxy, so the header could name any address.
 */
class CbeController extends Controller
{
    protected const TOKEN_TTL = 3600;

    protected const ABANDON_AFTER_HOURS = 24;

    protected const API_QUERY = 'NIYA_QUERY';

    protected const API_PAYMENT = 'NIYA_PAYMENT';

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PaymentSettlementService $settlement,
    ) {}

    protected function gateway(): ?CbeGateway
    {
        $gateway = $this->gateways->tryGet('cbe');

        return $gateway instanceof CbeGateway ? $gateway : null;
    }

    protected function callerAllowed(Request $request, CbeGateway $gateway): bool
    {
        return in_array((string) $request->server('REMOTE_ADDR'), $gateway->allowedIps(), true);
    }

    /* --------------------------------------------------------------- token */

    public function token(Request $request): JsonResponse
    {
        $gateway = $this->gateway();

        if (! $gateway || ! $this->callerAllowed($request, $gateway)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $ok = $gateway->isConfigured()
            && $request->input('grant_type') === 'client_credentials'
            && hash_equals($gateway->clientId(), (string) $request->input('client_id'))
            && hash_equals($gateway->clientSecret(), (string) $request->input('client_secret'));

        if (! $ok) {
            Log::warning('CBE token refused', ['ip' => $request->server('REMOTE_ADDR'), 'client_id' => $request->input('client_id')]);

            return response()->json([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed',
                'access_token' => null,
            ], 400);
        }

        $token = bin2hex(random_bytes(32));
        Cache::put('cbe:token:'.hash('sha256', $token), true, self::TOKEN_TTL);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'scope' => (string) ($request->input('scope') ?: 'Unified_Outgoing'),
            'expires_in' => self::TOKEN_TTL,
            'consented_on' => time(),
        ]);
    }

    protected function bearerValid(Request $request): bool
    {
        $token = (string) $request->bearerToken();

        return $token !== '' && Cache::has('cbe:token:'.hash('sha256', $token));
    }

    /* --------------------------------------------------------------- Query */

    public function query(Request $request): JsonResponse
    {
        $gateway = $this->gateway();

        if (! $gateway || ! $this->callerAllowed($request, $gateway)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $this->bearerValid($request)) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Missing, invalid or expired token'], 401);
        }

        $req = $this->body($request);
        $out = $this->echoed($req ?? [], self::API_QUERY) + [
            'Bill_Id' => (string) ($req['Bill_Id'] ?? ''),
            'Timestamp' => now()->toIso8601String(),
        ];
        $fail = fn (string $code, string $desc) => response()->json($out + [
            'Status' => 'FAILED', 'Response_Code' => $code, 'Response_Description' => $desc, 'Additional_Fields' => [],
        ]);

        try {
            if ($req === null) {
                return $fail('3', 'Validation error: body is not valid JSON');
            }
            if ($this->badAdditionalFields($req)) {
                return $fail('3', 'Validation error: Additional_Fields must be an array of Key/Value objects');
            }
            foreach (['End_To_End_Txn_Id', 'Bill_Id'] as $field) {
                if (! is_scalar($req[$field] ?? null) || trim((string) $req[$field]) === '') {
                    return $fail('3', "Validation error: {$field} is required");
                }
            }

            $billId = $this->normaliseBill((string) $req['Bill_Id']);
            $e2e = (string) $req['End_To_End_Txn_Id'];
            $payments = $this->resolve($billId);

            if ($payments->isEmpty()) {
                return $fail('1', 'Bill not found');
            }
            if (! $this->payable($payments)) {
                return $fail('2', 'Bill already paid or expired');
            }

            $order = $this->order($billId, $payments);

            // One End_To_End_Txn_Id belongs to one bill only.
            $usedElsewhere = PaymentGatewayOrder::query()
                ->where('gateway', 'cbe')
                ->where('reference', '!=', $billId)
                ->whereJsonContains('register_response->e2e', $e2e)
                ->exists();

            if ($usedElsewhere) {
                return $fail('3', 'Validation error: End_To_End_Txn_Id already used for another Bill_Id');
            }

            $register = (array) ($order->register_response ?? []);
            $seen = (array) ($register['e2e'] ?? []);
            if (! in_array($e2e, $seen, true)) {
                $seen[] = $e2e;
            }
            $register['e2e'] = array_slice($seen, -20);
            $order->forceFill(['register_response' => $register, 'last_checked_at' => now()])->save();

            $total = $this->total($payments);
            $payer = $payments->first()->membership?->payerMember();
            $fullName = trim((string) ($payer?->full_name ?? $payer?->user?->name ?? 'Niya Member'));
            [$first, $last] = array_pad(preg_split('/\s+/', $fullName, 2) ?: [], 2, '');
            $group = $payments->first()->membership?->equbGroup?->name;

            return response()->json($out + [
                'Bill_Id' => $billId,
                'Bill_Amount' => $total,
                'Penalty_Amount' => '0.00',
                'Total_Amount' => $total,
                'First_Name' => $first,
                'Last_Name' => $last !== '' ? $last : $first,
                'Full_Name' => $fullName,
                'Credit_Acct_Number' => $gateway->creditAccount(),
                'Tin_Number' => $gateway->tin(),
                'Payment_Reason' => 'Niya Equb contribution'.($group ? ' - '.$group : ''),
                'Transaction_Type' => 'EQUB_CONTRIBUTION',
                'Status' => 'SUCCESS',
                'Response_Code' => '0',
                'Response_Description' => 'Success',
                'Additional_Fields' => [['Key' => 'Contribution_Count', 'Value' => (string) $payments->count()]],
            ]);
        } catch (\Throwable $e) {
            Log::error('CBE Query failed', ['error' => $e->getMessage(), 'bill' => $req['Bill_Id'] ?? null]);

            return $fail('99', 'System error');
        }
    }

    /* ------------------------------------------------------------- payment */

    public function payment(Request $request): JsonResponse
    {
        $gateway = $this->gateway();

        if (! $gateway || ! $this->callerAllowed($request, $gateway)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $this->bearerValid($request)) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Missing, invalid or expired token'], 401);
        }

        $req = $this->body($request);
        $out = $this->echoed($req ?? [], self::API_PAYMENT) + ['Cbe_Txn_Ref' => (string) ($req['Cbe_Txn_Ref'] ?? '')];
        $respond = fn (string $status, string $code, string $desc, string $dref = '') => response()->json($out + [
            'Destination_txn_Ref' => $dref, 'Status' => $status, 'Response_Code' => $code,
            'Response_Description' => $desc, 'Additional_Fields' => [],
        ]);
        $fail = fn (string $code, string $desc, string $dref = '') => $respond('FAILED', $code, $desc, $dref);

        try {
            if ($req === null) {
                return $fail('3', 'Validation error: body is not valid JSON');
            }
            foreach (['End_To_End_Txn_Id', 'Cbe_Txn_Ref', 'Bill_Id', 'Amount'] as $field) {
                if (! is_scalar($req[$field] ?? null) || trim((string) $req[$field]) === '') {
                    return $fail('3', "Validation error: {$field} is required");
                }
            }
            if (! is_string($req['Full_Name'] ?? null) || trim($req['Full_Name']) === '') {
                return $fail('3', 'Validation error: Full_Name is required');
            }
            if (isset($req['Timestamp']) && $req['Timestamp'] !== '' && ! $this->validIso8601($req['Timestamp'])) {
                return $fail('3', 'Validation error: Timestamp must be an ISO 8601 date-time string');
            }
            if ($this->badAdditionalFields($req)) {
                return $fail('3', 'Validation error: Additional_Fields must be an array of Key/Value objects');
            }
            if ($this->sentAndDiffers($req, 'Credit_Acct_Number', $gateway->creditAccount())) {
                return $fail('3', 'Validation error: Credit_Acct_Number does not match the Query');
            }
            if ($this->sentAndDiffers($req, 'Tin_Number', $gateway->tin())) {
                return $fail('3', 'Validation error: Tin_Number does not match the Query');
            }
            $currency = strtoupper(trim((string) ($req['Currency'] ?? 'ETB')));
            if ($currency !== '' && $currency !== 'ETB') {
                return $fail('3', 'Validation error: Currency must be ETB');
            }

            $cbeRef = (string) $req['Cbe_Txn_Ref'];
            $billId = $this->normaliseBill((string) $req['Bill_Id']);
            $e2e = (string) $req['End_To_End_Txn_Id'];

            // Serialise everything about this bill, so two Payment calls for
            // it — even with different Cbe_Txn_Refs — cannot both succeed.
            $lock = Cache::lock('cbe:payment:'.$billId, 30);
            if (! $lock->block(15)) {
                return $fail('99', 'System error: payment for this Bill_Id is already being processed');
            }

            try {
                // A Cbe_Txn_Ref already recorded is a duplicate, never a second credit.
                $previous = PaymentGatewayOrder::query()
                    ->where('gateway', 'cbe')
                    ->where('external_order_id', $cbeRef)
                    ->first();

                if ($previous) {
                    if ($previous->reference === $billId) {
                        return $fail('2', 'Duplicate transaction: Cbe_Txn_Ref already recorded',
                            (string) (($previous->status_response ?? [])['destination_txn_ref'] ?? ''));
                    }

                    return $fail('3', 'Validation error: Cbe_Txn_Ref already used for another Bill_Id');
                }

                $payments = $this->resolve($billId);

                if ($payments->isEmpty()) {
                    return $fail('1', 'Bill not found');
                }
                if (! $this->payable($payments)) {
                    return $fail('2', 'Bill already paid or expired');
                }

                $order = $this->order($billId, $payments);

                if ($order->status === CbeGateway::STATUS_CONFIRMED) {
                    return $fail('2', 'Bill already paid or expired');
                }
                if (! in_array($e2e, (array) (($order->register_response ?? [])['e2e'] ?? []), true)) {
                    return $fail('3', 'Validation error: End_To_End_Txn_Id does not match a Query for this Bill_Id');
                }

                $total = $this->total($payments);
                if (! is_numeric($req['Amount']) || (int) round((float) $req['Amount'] * 100) !== (int) round((float) $total * 100)) {
                    return $fail('3', 'Validation error: Amount does not match Total_Amount ('.$total.')');
                }

                $dref = 'NTX'.now()->format('ymd').strtoupper(bin2hex(random_bytes(4)));

                // CBE's confirmation is recorded BEFORE crediting, so if
                // anything below fails the sweep can still settle it.
                $order->forceFill([
                    'external_order_id' => $cbeRef,
                    'status' => CbeGateway::STATUS_CONFIRMED,
                    'last_checked_at' => now(),
                    'status_response' => [
                        'destination_txn_ref' => $dref,
                        'confirmed_at' => now()->toIso8601String(),
                        'caller_ip' => (string) $request->server('REMOTE_ADDR'),
                        'request' => $req,
                    ],
                ])->save();
            } finally {
                $lock->release();
            }

            $result = $this->settlement->reconcile($gateway, $billId, 10);

            if (! ($result['success'] ?? false)) {
                Log::critical('CBE confirmed a payment but crediting did not complete; the sweep will retry', [
                    'bill' => $billId, 'cbe_txn_ref' => $cbeRef, 'result' => $result,
                ]);

                return $fail('99', 'System error', $dref);
            }

            Log::info('CBE payment credited', ['bill' => $billId, 'cbe_txn_ref' => $cbeRef, 'niya_ref' => $dref]);

            return $respond('SUCCESS', '0', 'Success', $dref);
        } catch (\Throwable $e) {
            Log::error('CBE Payment failed', ['error' => $e->getMessage(), 'bill' => $req['Bill_Id'] ?? null]);

            return $fail('99', 'System error');
        }
    }

    /* ------------------------------------------------------------- helpers */

    protected function body(Request $request): ?array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function echoed(array $req, string $default): array
    {
        $name = $req['Destination_Api_Name'] ?? '';

        return [
            'Destination_Api_Name' => is_scalar($name) && (string) $name !== '' ? (string) $name : $default,
            'End_To_End_Txn_Id' => is_scalar($req['End_To_End_Txn_Id'] ?? null) ? (string) $req['End_To_End_Txn_Id'] : '',
        ];
    }

    protected function normaliseBill(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    /** CBE-method contributions behind a Bill_Id: a row reference first, then a batch. */
    protected function resolve(string $billId): Collection
    {
        $rows = EqubPayment::query()
            ->where('payment_method', EqubPaymentMethod::Cbe->value)
            ->where('reference', $billId)
            ->get();

        if ($rows->isEmpty()) {
            $rows = EqubPayment::query()
                ->where('payment_method', EqubPaymentMethod::Cbe->value)
                ->where('batch_reference', $billId)
                ->get();
        }

        return $rows;
    }

    /** Every row pending and younger than the abandonment window. */
    protected function payable(Collection $payments): bool
    {
        $cutoff = now()->subHours(self::ABANDON_AFTER_HOURS);

        return $payments->every(fn (EqubPayment $p): bool => $p->status === EqubPaymentStatus::Pending
            && $p->created_at !== null
            && $p->created_at->gte($cutoff));
    }

    protected function total(Collection $payments): string
    {
        $minor = $payments->sum(fn (EqubPayment $p): int => (int) round(((float) $p->amount) * 100));

        return number_format($minor / 100, 2, '.', '');
    }

    protected function order(string $billId, Collection $payments): PaymentGatewayOrder
    {
        return PaymentGatewayOrder::firstOrCreate(
            ['gateway' => 'cbe', 'reference' => $billId],
            [
                'amount_minor' => (int) round(((float) $this->total($payments)) * 100),
                'currency' => 'ETB',
                'status' => CbeGateway::STATUS_OPEN,
                'register_response' => ['e2e' => []],
            ]
        );
    }

    protected function validIso8601(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }

    protected function badAdditionalFields(array $req): bool
    {
        if (! array_key_exists('Additional_Fields', $req) || $req['Additional_Fields'] === null) {
            return false;
        }

        $fields = $req['Additional_Fields'];

        if (! is_array($fields) || ! array_is_list($fields)) {
            return true;
        }

        foreach ($fields as $item) {
            if (! is_array($item) || (array_is_list($item) && $item !== [])) {
                return true;
            }
        }

        return false;
    }

    protected function sentAndDiffers(array $req, string $field, string $expected): bool
    {
        if (! isset($req[$field])) {
            return false;
        }

        $sent = trim((string) (is_scalar($req[$field]) ? $req[$field] : 'x'));

        return $sent !== '' && $sent !== $expected;
    }
}

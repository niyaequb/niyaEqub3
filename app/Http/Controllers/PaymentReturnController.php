<?php

namespace App\Http\Controllers;

use App\Contracts\HandlesCustomerReturn;
use App\Contracts\PaymentGateway;
use App\Services\EnvService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where a hosted checkout (EthSwitch NPG) sends the member back, and where it
 * may call us when an order changes.
 *
 * NOTHING HERE TRUSTS THE REQUEST. Both routes are public and anyone can call
 * them with any orderId. All they do is work out which of OUR references is
 * meant, and then ask the bank ourselves through the same
 * PaymentSettlementService::reconcile() the five-minute sweep uses: same lock,
 * same verification, same integrity checks, same one receipt per payer. A
 * forged call can at most make us ask EthSwitch a question a little early.
 */
class PaymentReturnController extends Controller
{
    /** The browser coming back from the checkout page. */
    public function customerReturn(
        Request $request,
        string $provider,
        PaymentGatewayManager $gateways,
        PaymentSettlementService $settlement,
    ): Response {
        [$gateway, $reference] = $this->resolve($request, $provider, $gateways);

        if (! $gateway || ! $reference) {
            return $this->page('unknown', null, null, null);
        }

        $state = $this->check($gateway, $reference, $settlement);

        return $this->page($state['state'], $gateway, $reference, $state['message']);
    }

    /** A server-to-server notification. Answers 200 unless we should be retried. */
    public function callback(
        Request $request,
        string $provider,
        PaymentGatewayManager $gateways,
        PaymentSettlementService $settlement,
    ): JsonResponse {
        [$gateway, $reference] = $this->resolve($request, $provider, $gateways);

        Log::info('Payment gateway callback received', [
            'provider' => $provider,
            'reference' => $reference,
            'params' => $request->except(['password', 'userName']),
        ]);

        if (! $gateway || ! $reference) {
            return response()->json(['status' => 'error', 'message' => 'Unknown order.'], 404);
        }

        $state = $this->check($gateway, $reference, $settlement);

        if ($state['state'] === 'busy') {
            return response()->json(['status' => 'error', 'message' => 'Busy, please retry.'], 503);
        }

        return response()->json(['status' => 'success', 'state' => $state['state']]);
    }

    /**
     * @return array{0: ?PaymentGateway, 1: ?string}
     */
    protected function resolve(Request $request, string $provider, PaymentGatewayManager $gateways): array
    {
        $gateway = $gateways->tryGet($provider);

        if (! $gateway instanceof HandlesCustomerReturn) {
            return [null, null];
        }

        return [$gateway, $gateway->referenceFromReturn($request->all())];
    }

    /**
     * @return array{state: string, message: ?string}
     */
    protected function check(PaymentGateway $gateway, string $reference, PaymentSettlementService $settlement): array
    {
        try {
            $result = $settlement->reconcile($gateway, $reference, 10);

            // An order with no contribution behind it (php artisan npg:test).
            // Still worth telling whoever is looking what the bank said.
            if (($result['message'] ?? '') === 'Payment not found') {
                $result = $gateway->verifyPayment($reference);
            }
        } catch (\Throwable $e) {
            Log::error('Checking a returned payment failed', [
                'gateway' => $gateway->slug(),
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return ['state' => 'processing', 'message' => null];
        }

        return [
            'state' => match (true) {
                (bool) ($result['success'] ?? false) => 'paid',
                (bool) ($result['locked'] ?? false) => 'busy',
                (bool) ($result['pending'] ?? false) => 'processing',
                default => 'failed',
            },
            'message' => $result['message'] ?? null,
        ];
    }

    protected function page(string $state, ?PaymentGateway $gateway, ?string $reference, ?string $detail): Response
    {
        [$title, $body, $colour] = match ($state) {
            'paid' => ['Payment received', 'Your Equb contribution has been paid. You will also get an SMS receipt.', '#15803d'],
            'failed' => ['Payment not completed', 'The card payment did not go through and nothing was charged. You can try again from the app.', '#b91c1c'],
            'unknown' => ['Payment not found', 'We could not find this payment. If money left your account, contact Niya support with the time you paid.', '#b45309'],
            default => ['Confirming your payment', 'We are still confirming this payment with the bank. It will show in the app within a few minutes. Do not pay again.', '#1d4ed8'],
        };

        $back = null;

        if ($gateway) {
            $prefix = strtoupper((string) config('payments.gateways.'.$gateway->slug().'.env_prefix', $gateway->slug()));
            $appUrl = trim((string) app(EnvService::class)->get($prefix.'_APP_RETURN_URL'));

            if ($appUrl !== '') {
                $back = $appUrl.(str_contains($appUrl, '?') ? '&' : '?').http_build_query([
                    'reference' => $reference,
                    'status' => $state === 'busy' ? 'processing' : $state,
                ]);
            }
        }

        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $refresh = in_array($state, ['processing', 'busy'], true) ? '<meta http-equiv="refresh" content="8">' : '';
        $button = $back ? '<a class="btn" href="'.$e($back).'">Back to Niya Equb</a>' : '<p class="hint">You can close this page and return to the app.</p>';
        $ref = $reference ? '<p class="ref">Reference: '.$e($reference).'</p>' : '';

        $t = $e($title);
        $b = $e($body);

        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
{$refresh}<title>{$t} · Niya Equb</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f4f5;color:#18181b;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
.card{background:#fff;border-radius:14px;max-width:420px;width:100%;padding:28px 24px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}
h1{font-size:22px;margin:0 0 10px;color:{$colour}}p{line-height:1.5;margin:0 0 14px}.ref{font-size:13px;color:#71717a}.hint{font-size:14px;color:#52525b}
.btn{display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600}
</style></head><body><div class="card"><h1>{$t}</h1><p>{$b}</p>{$ref}{$button}</div></body></html>
HTML;

        return response($html, 200)->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use App\Services\EnvService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\SmsService;
use App\Support\Payments\NumericReference;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end test of the CBE production API against the real code and schema.
 *
 * Everything runs inside ONE database transaction that is always rolled back,
 * and SMS sending is replaced by a fake, so no contribution, token, order,
 * commission or message survives the run.
 */
class CbeSelfTest extends Command
{
    protected $signature = 'cbe:selftest {--membership= : Equb membership id to use (default: first suitable active one)}';

    protected $description = 'Run the CBE token/Query/Payment flow in a rolled-back transaction';

    protected array $results = [];

    public function handle(EnvService $env): int
    {
        $gateway = app(PaymentGatewayManager::class)->tryGet('cbe');
        if (! $gateway || ! $gateway->isConfigured()) {
            $this->error('CBE is not configured. Run: php artisan cbe:setup');

            return self::FAILURE;
        }

        app()->instance(SmsService::class, new class(app(EnvService::class)) extends SmsService {
            public function sendSms(string $to, string $message, ?string $templateId = null, $sendable = null): array
            {
                return ['status' => 'success', 'message' => 'faked by cbe:selftest'];
            }
        });

        DB::beginTransaction();

        try {
            $membership = $this->option('membership')
                ? EqubMembership::findOrFail($this->option('membership'))
                : EqubMembership::query()->where('status', 'active')->get()
                    ->first(fn (EqubMembership $m) => (float) $m->contribution_amount > 0
                        && (float) $m->remaining_amount > 2 * (float) $m->contribution_amount);

            if (! $membership) {
                $this->error('No suitable active membership found; pass --membership=ID');

                return self::FAILURE;
            }

            $amount = (float) $membership->contribution_amount;
            $make = fn () => EqubPayment::create([
                'equb_membership_id' => $membership->id,
                'amount' => $amount,
                'payment_date' => now()->toDateString(),
                'payment_method' => EqubPaymentMethod::Cbe,
                'status' => EqubPaymentStatus::Pending,
            ]);
            $a = $make();
            $b = $make();
            $gateway->createOrder($a->reference, $amount, 'selftest');
            $gateway->createOrder($b->reference, $amount, 'selftest');
            $amt = number_format($amount, 2, '.', '');

            $this->check('reference is 10 digits', NumericReference::isValid($a->reference) && NumericReference::isValid($b->reference), $a->reference.' / '.$b->reference);

            $bad = $this->call_('token', ['grant_type' => 'client_credentials', 'client_id' => 'x', 'client_secret' => 'y'], form: true);
            $this->check('bad credentials -> 400', $bad['http'] === 400);

            $other = $this->call_('token', ['grant_type' => 'client_credentials', 'client_id' => $gateway->clientId(), 'client_secret' => $gateway->clientSecret()], form: true, ip: '8.8.8.8');
            $this->check('unknown caller IP -> 403', $other['http'] === 403);

            $tok = $this->call_('token', ['grant_type' => 'client_credentials', 'client_id' => $gateway->clientId(), 'client_secret' => $gateway->clientSecret(), 'scope' => 'Unified_Outgoing'], form: true);
            $token = (string) ($tok['body']['access_token'] ?? '');
            $this->check('token issued', $tok['http'] === 200 && $token !== '');

            $noauth = $this->call_('Query', ['End_To_End_Txn_Id' => 'ST1', 'Bill_Id' => $a->reference]);
            $this->check('no token -> 401', $noauth['http'] === 401);

            $q = fn (array $body) => $this->call_('Query', $body, token: $token);
            $p = fn (array $over) => $this->call_('payment', $over + [
                'Destination_Api_Name' => 'MB_NIYA_PAYMENT', 'End_To_End_Txn_Id' => 'ST1', 'Cbe_Txn_Ref' => 'STREF1',
                'Bill_Id' => $a->reference, 'Amount' => $amt, 'Currency' => 'ETB', 'Full_Name' => 'Self Test',
                'Credit_Acct_Number' => $gateway->creditAccount(), 'Tin_Number' => $gateway->tin(),
                'Timestamp' => now()->format('Y-m-d\TH:i:s'), 'Additional_Fields' => [['Key' => '', 'Value' => '']],
            ], token: $token);
            $code = fn (array $r) => (string) ($r['body']['Response_Code'] ?? 'http '.$r['http']);

            $this->check('Query unknown bill -> 1', $code($q(['End_To_End_Txn_Id' => 'ST0', 'Bill_Id' => '1111111111'])) === '1');
            $this->check('Query bad Additional_Fields -> 3', $code($q(['End_To_End_Txn_Id' => 'ST1', 'Bill_Id' => $a->reference, 'Additional_Fields' => 'x'])) === '3');
            $qa = $q(['Destination_Api_Name' => 'MB_NIYA_QUERY', 'End_To_End_Txn_Id' => 'ST1', 'Bill_Id' => $a->reference]);
            $this->check('Query bill A -> 0 with amount', $code($qa) === '0' && ($qa['body']['Total_Amount'] ?? '') === $amt, ($qa['body']['Full_Name'] ?? '').' '.($qa['body']['Total_Amount'] ?? ''));
            $this->check('Destination_Api_Name echoed', ($qa['body']['Destination_Api_Name'] ?? '') === 'MB_NIYA_QUERY');
            $this->check('Query bill B with A\'s E2E -> 3', $code($q(['End_To_End_Txn_Id' => 'ST1', 'Bill_Id' => $b->reference])) === '3');

            $this->check('Pay wrong E2E -> 3', $code($p(['End_To_End_Txn_Id' => 'ST9'])) === '3');
            $this->check('Pay missing Full_Name -> 3', $code($this->call_('payment', ['End_To_End_Txn_Id' => 'ST1', 'Cbe_Txn_Ref' => 'STREF2', 'Bill_Id' => $a->reference, 'Amount' => $amt], token: $token)) === '3');
            $this->check('Pay bad Timestamp -> 3', $code($p(['Cbe_Txn_Ref' => 'STREF3', 'Timestamp' => 1792499000])) === '3');
            $this->check('Pay wrong account -> 3', $code($p(['Cbe_Txn_Ref' => 'STREF4', 'Credit_Acct_Number' => '1000999999999'])) === '3');
            $this->check('Pay wrong TIN -> 3', $code($p(['Cbe_Txn_Ref' => 'STREF5', 'Tin_Number' => '0999999999'])) === '3');
            $this->check('Pay wrong amount -> 3', $code($p(['Cbe_Txn_Ref' => 'STREF6', 'Amount' => '1.00'])) === '3');

            $noName = $p([]); // the valid payment (STREF1), after every rejection above
            $dref = (string) ($noName['body']['Destination_txn_Ref'] ?? '');
            $this->check('Valid payment -> 0 with NTX ref', $code($noName) === '0' && str_starts_with($dref, 'NTX'), $dref);
            $this->check('Response uses Destination_txn_Ref only', array_key_exists('Destination_txn_Ref', $noName['body']) && ! array_key_exists('Destination_Txn_Ref', $noName['body']));
            $a->refresh();
            $this->check('Contribution marked paid', $a->status === EqubPaymentStatus::Paid && $a->bank_transaction_id === 'STREF1', $a->status->value.' / '.$a->bank_transaction_id);

            $dup = $p([]);
            $this->check('Duplicate Cbe_Txn_Ref -> 2, same ref', $code($dup) === '2' && ($dup['body']['Destination_txn_Ref'] ?? '') === $dref);
            $this->check('Same Cbe_Txn_Ref on other bill -> 3', $code($p(['Bill_Id' => $b->reference])) === '3');
            $this->check('Query after paid -> 2', $code($q(['End_To_End_Txn_Id' => 'ST1', 'Bill_Id' => $a->reference])) === '2');
        } catch (\Throwable $e) {
            $this->error('Self-test crashed: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
            $this->results[] = ['CRASH', 'FAIL', $e->getMessage()];
        } finally {
            DB::rollBack();
        }

        $this->table(['Check', 'Result', 'Detail'], $this->results);
        $failed = collect($this->results)->where(1, 'FAIL')->count();
        $this->line($failed === 0 ? '<info>ALL PASSED</info> (everything rolled back)' : "<error>{$failed} FAILED</error> (everything rolled back)");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function check(string $name, bool $ok, string $detail = ''): void
    {
        $this->results[] = [$name, $ok ? 'pass' : 'FAIL', $detail];
    }

    protected function call_(string $path, array $body, ?string $token = null, bool $form = false, string $ip = '127.0.0.1'): array
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ($form) {
            $request = Request::create('/api/cbe/'.$path, 'POST', $body, [], [], $server);
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $request = Request::create('/api/cbe/'.$path, 'POST', [], [], [], $server, json_encode($body));
        }

        $response = app(Kernel::class)->handle($request);

        return ['http' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getContent(), true)];
    }
}

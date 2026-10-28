<?php

namespace App\Services;

use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Models\CallCenterLog;
use App\Models\EqubMembership;
use App\Models\EqubTermination;
use App\Support\Equb\MembershipStanding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Who has stopped paying, for how long, and what the call center did about it.
 *
 * "Days unpaid" is counted from the member's last PAID contribution, or from
 * the day they joined if they have never paid. The amount owed comes from the
 * same standing calculation the reports and the draw use, so the call center
 * never quotes a member a different number from the one on the Outstanding
 * report.
 */
class CallCenterService
{
    /** Category key => [label, min days unpaid, max days unpaid|null]. */
    public const CATEGORIES = [
        'd7_14' => ['7 – 14 days', 7, 14],
        'd15_30' => ['15 – 30 days', 15, 30],
        'd31_60' => ['31 – 60 days', 31, 60],
        'd60_plus' => ['Over 60 days', 61, null],
    ];

    /** @var array<int, MembershipStanding|null> */
    protected array $standings = [];

    /** SQL for "the day this place was last paid for, or joined if never". */
    public static function referenceDateSql(): string
    {
        $paid = EqubPaymentStatus::Paid->value;

        return "coalesce((select max(coalesce(ep.payment_date, ep.created_at)) from equb_payments ep
                    where ep.equb_membership_id = equb_memberships.id and ep.status = '{$paid}'),
                 equb_memberships.join_date, equb_memberships.created_at)";
    }

    /** Live memberships in live Equbs: the people the call center works on. */
    public static function liveMemberships(Builder $query): Builder
    {
        return $query
            ->where('equb_memberships.status', EqubMembershipStatus::Active->value)
            ->whereHas('equbGroup', fn (Builder $g) => $g->whereNotIn('status', ['cancelled', 'completed']));
    }

    /** Places with nothing paid for at least $minDays (and at most $maxDays). */
    public static function unpaidBetween(Builder $query, int $minDays, ?int $maxDays = null): Builder
    {
        $ref = self::referenceDateSql();

        $query->whereRaw("{$ref} <= ?", [now()->subDays($minDays)->endOfDay()]);

        if ($maxDays !== null) {
            $query->whereRaw("{$ref} > ?", [now()->subDays($maxDays + 1)->endOfDay()]);
        }

        return $query;
    }

    public static function neverPaid(Builder $query): Builder
    {
        return $query->whereDoesntHave('payments', fn (Builder $p) => $p->where('status', EqubPaymentStatus::Paid->value));
    }

    public function standing(EqubMembership $membership): ?MembershipStanding
    {
        if (! array_key_exists($membership->id, $this->standings)) {
            try {
                $this->standings[$membership->id] = $membership->standing();
            } catch (\Throwable $e) {
                Log::warning('[CallCenter] could not compute standing', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                $this->standings[$membership->id] = null;
            }
        }

        return $this->standings[$membership->id];
    }

    public function daysUnpaid(EqubMembership $membership): int
    {
        $last = $membership->payments()
            ->where('status', EqubPaymentStatus::Paid->value)
            ->max(DB::raw('coalesce(payment_date, created_at)'));

        $reference = $last ?? $membership->join_date ?? $membership->created_at;

        return $reference ? (int) max(0, \Illuminate\Support\Carbon::parse($reference)->startOfDay()->diffInDays(now()->startOfDay())) : 0;
    }

    public function arrears(EqubMembership $membership): float
    {
        return round((float) ($this->standing($membership)?->arrears ?? 0), 2);
    }

    public function phone(EqubMembership $membership): ?string
    {
        return $membership->responsibility_phone
            ?: $membership->payerUser()?->phone;
    }

    public function logCall(EqubMembership $membership, array $data): CallCenterLog
    {
        return CallCenterLog::create([
            'equb_membership_id' => $membership->id,
            'member_id' => $membership->payerMemberId(),
            'called_by' => Auth::id(),
            'outcome' => $data['outcome'],
            'promised_payment_date' => $data['promised_payment_date'] ?? null,
            'feedback' => $data['feedback'] ?? null,
            'arrears_at_call' => $this->arrears($membership),
            'days_unpaid_at_call' => $this->daysUnpaid($membership),
        ]);
    }

    /** Remove a member from an Equb place for not paying. */
    public function terminate(EqubMembership $membership, string $reason, ?string $note = null, bool $notify = false): EqubTermination
    {
        return DB::transaction(function () use ($membership, $reason, $note, $notify): EqubTermination {
            $standing = $this->standing($membership);

            $termination = EqubTermination::create([
                'equb_membership_id' => $membership->id,
                'member_id' => $membership->payerMemberId(),
                'terminated_by' => Auth::id(),
                'reason' => $reason,
                'note' => $note,
                'arrears_at_termination' => round((float) ($standing?->arrears ?? 0), 2),
                'paid_at_termination' => round((float) ($standing?->paidAmount ?? $membership->total_paid), 2),
                'days_unpaid' => $this->daysUnpaid($membership),
                'had_won' => $membership->hasReceivedPayout(),
            ]);

            $membership->update([
                'status' => EqubMembershipStatus::Cancelled,
                'termination_requested_at' => null,
            ]);

            if ($notify && ($phone = $this->phone($membership))) {
                try {
                    $equb = $membership->equbGroup?->name ?? 'Equb';
                    app(SmsService::class)->sendSms(
                        $phone,
                        "Dear member, your place in {$equb} has been terminated because the contributions were not paid. Please contact Niya Equb for details."
                    );
                } catch (\Throwable $e) {
                    Log::warning('[CallCenter] termination SMS failed', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                }
            }

            return $termination;
        });
    }

    /** Undo a termination: the member is back in the Equb. */
    public function reinstate(EqubTermination $termination): void
    {
        DB::transaction(function () use ($termination): void {
            $termination->membership?->update(['status' => EqubMembershipStatus::Active]);
            $termination->update([
                'reinstated_at' => now(),
                'reinstated_by' => Auth::id(),
            ]);
        });
    }

    public static function canUse(string $permission): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can($permission)
        );
    }
}

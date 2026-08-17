<?php

namespace App\Services;

use App\Enums\EqubDurationType;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPackageType;
use App\Models\Cohort;
use App\Models\EqubGroup;
use App\Models\EqubMembership;
use App\Models\EqubPackage;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EqubMembershipService
{
    public function __construct(
        protected SmsService $smsService,
        protected FcmService $fcmService,
    ) {}
    /**
     * Join a member to an Equb group. Validates normal vs flexible rules, max members,
     * registration window, and duplicate membership. Calculates membership end date.
     *
     * @return array{success: bool, membership?: EqubMembership, message?: string}
     */
    public function joinEqub(int $memberId, int $equbGroupId): array
    {
        $member = Member::find($memberId);
        $group = EqubGroup::with('package')->find($equbGroupId);

        if (! $member) {
            return ['success' => false, 'message' => 'Member not found.'];
        }

        if (! $group) {
            return ['success' => false, 'message' => 'Equb group not found.'];
        }

        if ($group->status == EqubGroupStatus::Draft || $group->status == EqubGroupStatus::Completed || $group->status == EqubGroupStatus::Cancelled) {
            return ['success' => false, 'message' => 'Registration is not open for this group.'];
        }

        if ($group->registration_open_at->isFuture()) {
            return ['success' => false, 'message' => 'Registration has not opened yet.'];
        }

        // if ($group->registration_close_at && $group->registration_close_at->isPast()) {
        //     return ['success' => false, 'message' => 'Registration has closed.'];
        // }

        // if ($group->max_members && $group->current_members_count >= $group->max_members) {
        //     return ['success' => false, 'message' => 'Group is full.'];
        // }

        $existing = EqubMembership::where('equb_group_id', $equbGroupId)
            ->where('member_id', $memberId)
            ->whereIn('status', [EqubMembershipStatus::Active, EqubMembershipStatus::Completed])
            ->exists();

        if ($existing) {
            return ['success' => false, 'message' => 'Member is already in this Equb group.'];
        }

        $package = $group->package;

        // if ($package->isNormal()) {
        //     $activeNormal = EqubMembership::where('member_id', $memberId)->whereHas('equbGroup', fn ($q) => $q->whereHas('package', fn ($p) => $p->where('type', EqubPackageType::Normal)))->where('status', EqubMembershipStatus::Active)->exists();

        //     if ($activeNormal) {
        //         return ['success' => false, 'message' => 'Member cannot join another normal Equb while active in one.'];
        //     }
        // }

        $amount = (float) $group->fixed_contribution_amount;
        $frequency = (int) $group->contribution_frequency_days;

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid contribution amount in group settings.'];
        }

        if ($frequency <= 0) {
            return ['success' => false, 'message' => 'Invalid contribution frequency in group settings.'];
        }

        $joinDate = now();
        $calculatedEndDate = $this->calculateEndDate($group, $joinDate, $frequency);
        $cohort = $this->resolveCohort($group, $joinDate);

        try {
            $membership = DB::transaction(function () use ($group, $memberId, $amount, $frequency, $joinDate, $calculatedEndDate, $cohort) {
                $membership = EqubMembership::create([
                    'equb_group_id' => $group->id,
                    'member_id' => $memberId,
                    'cohort_id' => $cohort->id,
                    'contribution_amount' => $amount,
                    'contribution_frequency_days' => $frequency,
                    'join_date' => $joinDate,
                    'calculated_end_date' => $calculatedEndDate,
                    'status' => EqubMembershipStatus::Active,
                ]);

                $group->increment('current_members_count');

                return $membership->load(['equbGroup.package', 'member.user']);
            });

            return ['success' => true, 'membership' => $membership];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to join Equb: '.$e->getMessage()];
        }
    }

    /**
     * Open a seat in a group for someone who has no Niya account.
     *
     * Identical to joinEqub in every respect that touches money: same
     * contribution, same frequency, same cohort weighting, same end date, and
     * it bumps the same head-count. The only difference is that member_id is
     * left null and a sponsor is recorded instead, because there is no account
     * to attach the obligation to.
     *
     * Deliberately not routed through joinEqub: that method's guards are all
     * about the member joining ("already in this group", "member not found"),
     * and none of them mean anything for a seat with no account behind it.
     *
     * @return array{success: bool, membership?: EqubMembership, message?: string}
     */
    public function createResponsibilitySeat(EqubGroup $group, Member $sponsor, array $person): array
    {
        $name = trim((string) ($person['name'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Give this person a name.'];
        }

        if (in_array($group->status, [EqubGroupStatus::Completed, EqubGroupStatus::Cancelled], true)) {
            return ['success' => false, 'message' => 'This Equb is closed.'];
        }

        $amount = (float) $group->fixed_contribution_amount;
        $frequency = (int) $group->contribution_frequency_days;

        if ($amount <= 0 || $frequency <= 0) {
            return ['success' => false, 'message' => 'Invalid contribution settings on this Equb.'];
        }

        $joinDate = now();
        $cohort = $this->resolveCohort($group, $joinDate);

        try {
            $membership = DB::transaction(function () use ($group, $sponsor, $person, $name, $amount, $frequency, $joinDate, $cohort) {
                $membership = EqubMembership::create([
                    'equb_group_id' => $group->id,
                    'member_id' => null,
                    'sponsor_member_id' => $sponsor->id,
                    'responsibility_name' => $name,
                    'responsibility_phone' => $person['phone'] ?? null,
                    'responsibility_relation' => $person['relation'] ?? null,
                    'responsibility_note' => $person['note'] ?? null,
                    'role' => \App\Enums\EqubMembershipRole::Member,
                    'cohort_id' => $cohort->id,
                    'contribution_amount' => $amount,
                    'contribution_frequency_days' => $frequency,
                    'join_date' => $joinDate,
                    'calculated_end_date' => $this->calculateEndDate($group, $joinDate, $frequency),
                    'status' => EqubMembershipStatus::Active,
                ]);

                $group->increment('current_members_count');

                return $membership->load(['equbGroup.package', 'sponsor.user']);
            });

            return ['success' => true, 'membership' => $membership];
        } catch (\Throwable $e) {
            Log::error('Failed to open a responsibility seat: '.$e->getMessage());

            return ['success' => false, 'message' => 'Could not add '.$name.' to this Equb.'];
        }
    }

    /**
     * The month/year cohort a seat joins into. Shared by real members and
     * responsibility seats so late joiners of both kinds keep the same
     * win-weight compensation.
     */
    protected function resolveCohort(EqubGroup $group, Carbon $joinDate): Cohort
    {
        return Cohort::firstOrCreate(
            [
                'equb_group_id' => $group->id,
                'month' => $joinDate->month,
                'year' => $joinDate->year,
            ],
            [
                'name' => $joinDate->format('F Y'),
                'win_weight' => 1.00, // Default weight
                'is_active' => true,
            ]
        );
    }

    protected function calculateEndDate(EqubGroup $group, Carbon $joinDate, int $frequencyDays, ?int $memberCount = null): ?Carbon
    {
        if ($group->duration_type === \App\Enums\EqubDurationType::Fixed && $group->duration_value !== null) {
            $endDate = $joinDate->copy();

            switch ($group->duration_unit) {
                case \App\Enums\EqubDurationUnit::Weeks:
                    $endDate->addWeeks($group->duration_value);
                    break;
                case \App\Enums\EqubDurationUnit::Months:
                    $endDate->addMonths($group->duration_value);
                    break;
                case \App\Enums\EqubDurationUnit::Days:
                default:
                    $endDate->addDays($group->duration_value);
                    break;
            }

            return $endDate;
        }

        if ($group->duration_type === \App\Enums\EqubDurationType::PerMember) {
            $count = $memberCount ?? ($group->max_members ?? $group->current_members_count + 1);

            // Factor in dynamic draws per day
            $membersPerDraw = config('services.equb.members_per_draw', 50);
            $drawsPerPeriod = (int) ceil($count / $membersPerDraw);
            $drawsPerPeriod = max(1, $drawsPerPeriod);

            $cycles = (int) ceil($count / $drawsPerPeriod);
            $totalDays = $cycles * $frequencyDays;

            return $joinDate->copy()->addDays($totalDays - 1);
        }

        return null;
    }

    /**
     * Check if a membership is eligible for completion and mark it as such.
     * Criteria: Won the draw AND paid all contributions.
     */
    public function completeIfEligible(EqubMembership $membership): bool
    {
        if ($membership->status !== EqubMembershipStatus::Active) {
            return false;
        }

        if (!$membership->has_won) {
            return false;
        }

        if ($membership->remaining_amount > 0) {
            return false;
        }

        try {
            DB::transaction(function () use ($membership) {
                $membership->update(['status' => EqubMembershipStatus::Completed]);

                // Track completion
                Log::info("Equb Membership #{$membership->id} completed.");

                $this->sendCompletionNotification($membership);
            });
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to complete membership #{$membership->id}: " . $e->getMessage());
            return false;
        }
    }

    protected function sendCompletionNotification(EqubMembership $membership): void
    {
        // A responsibility seat has no account behind it, so the sponsor is
        // the one told about it. payerMember() resolves to the member on a
        // normal membership and to the sponsor on a seat, which is why this
        // never reads member->user directly — that was a fatal null access the
        // moment a seat completed.
        $user = $membership->payerUser();
        $phone = $user?->phone;
        $groupName = $membership->equbGroup?->name ?? 'Niya Equb';

        $message = $membership->isResponsibilitySeat()
            ? "Congratulations! The place you hold for {$membership->displayName()} in {$groupName} is fully completed. "
                .'All contributions are paid and the win amount has been received. Thank you for using Niya Equb!'
            : "Congratulations! Your journey with {$groupName} is fully completed. "
                .'You have paid all contributions and received your win amount. Thank you for using Niya Equb!';

        if ($phone) {
            $this->smsService->sendSms($phone, $message, null, null);
        }

        if ($user) {
            $this->fcmService->sendToUser($user->id, [
                'type' => 'equb_membership_completed',
                'equb_membership_id' => (string) $membership->id,
                'equb_group_name' => $groupName,
                'is_responsibility_seat' => $membership->isResponsibilitySeat() ? '1' : '0',
                'seat_name' => $membership->displayName(),
            ], "Ekub Completed!", "Your {$groupName} journey is successfully finished.");
        }
    }

    /**
     * Let a member leave an Equb.
     *
     * REFUSED OUTRIGHT ONCE THEY HAVE WON. This used to check only whether any
     * payment had been marked Paid, which left the worst case wide open: a
     * member who wins the very first round before their own contribution has
     * been reconciled had no Paid row, passed the check, and could leave
     * holding the entire pot. The other members had funded a payout for
     * someone who was no longer in the circle.
     *
     * The authoritative rule now lives on the model — see
     * EqubMembership::exitBlockReason() — so this path, the owner's remove
     * button and any future one cannot drift apart.
     *
     * @param  bool  $force  Support override. Only ever passed by an
     *                       authenticated admin who has settled the debt by
     *                       other means; never reachable from the member API.
     */
    public function leaveEqub(EqubMembership $membership, bool $force = false): array
    {
        // Re-read inside the transaction rather than trusting the instance the
        // caller handed over. A draw running concurrently sets has_won on a
        // different copy of this row, and a stale in-memory model would sail
        // past the guard.
        try {
            return DB::transaction(function () use ($membership, $force) {
                /** @var EqubMembership|null $fresh */
                $fresh = EqubMembership::whereKey($membership->getKey())->lockForUpdate()->first();

                if (! $fresh) {
                    return ['success' => false, 'message' => 'That membership no longer exists.'];
                }

                $blocked = $fresh->exitBlockReason();

                if ($blocked !== null && ! $force) {
                    return ['success' => false, 'message' => $blocked];
                }

                if ($blocked !== null && $force) {
                    Log::warning('Equb exit forced by override', [
                        'membership_id' => $fresh->id,
                        'equb_group_id' => $fresh->equb_group_id,
                        'member_id' => $fresh->member_id,
                        'reason_overridden' => $blocked,
                        'has_received_payout' => $fresh->hasReceivedPayout(),
                        'remaining_amount' => $fresh->remaining_amount,
                    ]);
                }

                $group = $fresh->equbGroup;

                // Only ever a hard delete for a membership with no history: no
                // payout, no contributions. Anything with a win attached is
                // withdrawn through the admin path instead, which preserves the
                // row so the draw records it points at stay readable.
                $fresh->payments()->delete();
                $fresh->delete();

                if ($group && $group->current_members_count > 0) {
                    $group->decrement('current_members_count');
                }

                return ['success' => true, 'message' => 'You have successfully left the Equb.'];
            });
        } catch (\Throwable $e) {
            Log::error("Failed to leave Equb for membership #{$membership->id}: ".$e->getMessage());

            return ['success' => false, 'message' => 'Could not leave this Equb. Please try again.'];
        }
    }
}

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

        // Find or create cohort for the given month/year
        $cohort = Cohort::firstOrCreate(
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
        $member = $membership->member;
        $user = $member->user;
        $phone = $user?->phone;
        $groupName = $membership->equbGroup?->name ?? 'Niya Equb';

        $message = "Congratulations! Your journey with {$groupName} is fully completed. ";
        $message .= "You have paid all contributions and received your win amount. Thank you for using Niya Equb!";

        if ($phone) {
            $this->smsService->sendSms($phone, $message, null, null);
        }

        if ($user) {
            $this->fcmService->sendToUser($user->id, [
                'type' => 'equb_membership_completed',
                'equb_membership_id' => (string) $membership->id,
                'equb_group_name' => $groupName,
            ], "Ekub Completed!", "Your {$groupName} journey is successfully finished.");
        }
    }

    /**
     * Allow a member to leave an Equb group if no payments have been made.
     */
    public function leaveEqub(EqubMembership $membership): array
    {
        // Check if there are any 'paid' payments
        $hasPaid = $membership->payments()->where('status', \App\Enums\EqubPaymentStatus::Paid)->exists();

        if ($hasPaid) {
            return ['success' => false, 'message' => 'You cannot leave this Equb because you have already made payments.'];
        }

        try {
            DB::transaction(function () use ($membership) {
                $group = $membership->equbGroup;

                // Delete all payments (including pending ones)
                $membership->payments()->delete();

                // Delete the membership
                $membership->delete();

                // Decrement group member count
                if ($group) {
                    $group->decrement('current_members_count');
                }
            });

            return ['success' => true, 'message' => 'You have successfully left the Equb.'];
        } catch (\Throwable $e) {
            Log::error("Failed to leave Equb for membership #{$membership->id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to leave Equb: ' . $e->getMessage()];
        }
    }
}

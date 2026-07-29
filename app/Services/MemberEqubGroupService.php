<?php

namespace App\Services;

use App\Enums\EqubGroupModerationStatus;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubGroupVisibility;
use App\Enums\EqubInvitationStatus;
use App\Enums\EqubMembershipRole;
use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPackageType;
use App\Enums\EqubPaymentStatus;
use App\Enums\WinnerSelectionMode;
use App\Models\EqubGroup;
use App\Models\EqubGroupInvitation;
use App\Models\EqubMembership;
use App\Models\EqubPackage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle of a member-created Equb group: create, invite, join, start.
 *
 * Everything downstream (contributions, Chapa, commissions, reminders) is the
 * existing platform machinery — a group Equb is an EqubGroup with an owner.
 */
class MemberEqubGroupService
{
    public function __construct(
        protected EqubMembershipService $membershipService,
        protected EqubGroupService $groupService,
        protected GroupDrawService $drawService,
        protected SmsService $smsService,
        protected FcmService $fcmService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message?: string, group?: EqubGroup}
     */
    public function create(Member $owner, array $data): array
    {
        $package = EqubPackage::find($data['equb_package_id']);

        if (! $package || ! $package->is_active) {
            return ['success' => false, 'message' => 'That Equb package is not available.'];
        }

        $amount = $this->resolveContribution($package, $data['contribution_amount'] ?? null);

        if ($amount === null) {
            return [
                'success' => false,
                'message' => 'Choose a contribution between '
                    .number_format((float) $package->min_contribution_amount, 2).' and '
                    .number_format((float) $package->max_contribution_amount, 2).' ETB.',
            ];
        }

        $maxMembers = (int) ($data['max_members'] ?? $package->max_members ?? 0);

        if ($maxMembers < 2) {
            return ['success' => false, 'message' => 'A group Equb needs room for at least 2 members.'];
        }

        $mode = WinnerSelectionMode::from($data['winner_selection_mode'] ?? WinnerSelectionMode::Single->value);
        $modeError = $this->validateWinnerConfig($mode, $data, $maxMembers);

        if ($modeError !== null) {
            return ['success' => false, 'message' => $modeError];
        }

        $needsApproval = (bool) config('services.equb.group_requires_approval', true);
        $frequency = (int) ($package->contribution_frequency_days ?: 1);

        try {
            $group = DB::transaction(function () use ($owner, $package, $data, $amount, $maxMembers, $mode, $needsApproval, $frequency) {
                $group = EqubGroup::create([
                    'equb_package_id' => $package->id,
                    'owner_member_id' => $owner->id,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'visibility' => EqubGroupVisibility::Private,
                    'moderation_status' => $needsApproval
                        ? EqubGroupModerationStatus::Pending
                        : EqubGroupModerationStatus::Approved,
                    'approved_at' => $needsApproval ? null : now(),
                    'allow_member_invites' => (bool) ($data['allow_member_invites'] ?? false),
                    'fixed_contribution_amount' => $amount,
                    'contribution_frequency_days' => $frequency,
                    'duration_type' => $package->duration_type,
                    'duration_value' => $data['duration_value'] ?? $maxMembers,
                    'duration_unit' => $package->duration_unit ?? \App\Enums\EqubDurationUnit::Days,
                    'terms_content' => $package->terms_content,
                    'registration_open_at' => now(),
                    'equb_start_date' => $data['equb_start_date'] ?? null,
                    'max_members' => $maxMembers,
                    'status' => EqubGroupStatus::Registration,
                    'draw_type' => \App\Enums\EqubDrawType::Manual,
                    'winner_selection_mode' => $mode,
                    'winners_per_draw' => $data['winners_per_draw'] ?? null,
                    'min_winners_per_draw' => $data['min_winners_per_draw'] ?? null,
                    'max_winners_per_draw' => $data['max_winners_per_draw'] ?? null,
                    'draw_requires_up_to_date' => (bool) ($data['draw_requires_up_to_date'] ?? true),
                    'current_members_count' => 0,
                ]);

                // The creator is the first member — reuse the standard join path
                // so cohorts, end dates and contribution locking stay identical.
                $joined = $this->membershipService->joinEqub($owner->id, $group->id);

                if (! $joined['success']) {
                    throw new \RuntimeException($joined['message'] ?? 'Could not add you to the group.');
                }

                $joined['membership']->update(['role' => EqubMembershipRole::Owner]);

                return $group->fresh(['package', 'owner.user']);
            });
        } catch (\Throwable $e) {
            Log::error('Group Equb creation failed: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }

        $this->subscribeToTopic($owner, $group);

        return ['success' => true, 'group' => $group];
    }

    /**
     * @param  array<int>  $memberIds
     * @param  array<string>  $phones
     * @return array{success: bool, message?: string, invited: int, skipped: array<int, string>}
     */
    public function invite(EqubGroup $group, Member $inviter, array $memberIds = [], array $phones = [], ?string $message = null): array
    {
        if (! $this->canInvite($group, $inviter)) {
            return ['success' => false, 'message' => 'You cannot invite members to this Equb.', 'invited' => 0, 'skipped' => []];
        }

        if ($group->status !== EqubGroupStatus::Registration) {
            return ['success' => false, 'message' => 'This Equb has already started.', 'invited' => 0, 'skipped' => []];
        }

        $invited = 0;
        $skipped = [];
        $capacity = $this->remainingCapacity($group);

        foreach ($memberIds as $memberId) {
            if ($capacity <= 0) {
                $skipped[] = 'Group is full';
                break;
            }

            $member = Member::with('user')->find($memberId);

            if (! $member) {
                $skipped[] = "Member #{$memberId} not found";

                continue;
            }

            if ($this->alreadyInGroup($group, $member->id)) {
                $skipped[] = ($member->full_name ?? "Member #{$memberId}").' is already in this Equb';

                continue;
            }

            if ($this->hasPendingInvite($group, $member->id)) {
                $skipped[] = ($member->full_name ?? "Member #{$memberId}").' was already invited';

                continue;
            }

            $invitation = EqubGroupInvitation::create([
                'equb_group_id' => $group->id,
                'invited_by_member_id' => $inviter->id,
                'member_id' => $member->id,
                'phone' => $member->user?->phone,
                'message' => $message,
                'status' => EqubInvitationStatus::Pending,
            ]);

            $this->notifyInvitee($group, $inviter, $invitation, $member);
            $invited++;
            $capacity--;
        }

        // Phone numbers that are not on the platform yet: send a join link by SMS.
        foreach ($phones as $phone) {
            if ($capacity <= 0) {
                $skipped[] = 'Group is full';
                break;
            }

            $normalised = $this->smsService->formatPhoneNumber($phone);
            $existing = Member::whereHas('user', fn ($q) => $q->where('phone', $normalised))->first();

            if ($existing) {
                if ($this->alreadyInGroup($group, $existing->id) || $this->hasPendingInvite($group, $existing->id)) {
                    $skipped[] = $normalised.' was already invited';

                    continue;
                }
            }

            $invitation = EqubGroupInvitation::create([
                'equb_group_id' => $group->id,
                'invited_by_member_id' => $inviter->id,
                'member_id' => $existing?->id,
                'phone' => $normalised,
                'message' => $message,
                'status' => EqubInvitationStatus::Pending,
            ]);

            $this->notifyInvitee($group, $inviter, $invitation, $existing);
            $invited++;
            $capacity--;
        }

        return [
            'success' => $invited > 0 || $skipped === [],
            'invited' => $invited,
            'skipped' => $skipped,
            'message' => $invited > 0 ? "{$invited} invitation(s) sent." : 'No invitations were sent.',
        ];
    }

    /**
     * @return array{success: bool, message?: string, membership?: EqubMembership}
     */
    public function acceptInvitation(EqubGroupInvitation $invitation, Member $member): array
    {
        if (! $invitation->isPending()) {
            return ['success' => false, 'message' => 'This invitation is no longer valid.'];
        }

        $group = $invitation->equbGroup;

        if (! $group || $group->status !== EqubGroupStatus::Registration) {
            return ['success' => false, 'message' => 'This Equb is no longer accepting members.'];
        }

        if ($this->remainingCapacity($group) <= 0) {
            return ['success' => false, 'message' => 'This Equb is already full.'];
        }

        $joined = $this->membershipService->joinEqub($member->id, $group->id);

        if (! $joined['success']) {
            return $joined;
        }

        $membership = $joined['membership'];
        $membership->update([
            'role' => EqubMembershipRole::Member,
            'invited_by_member_id' => $invitation->invited_by_member_id,
        ]);

        $invitation->update([
            'status' => EqubInvitationStatus::Accepted,
            'member_id' => $member->id,
            'responded_at' => now(),
        ]);

        $this->subscribeToTopic($member, $group);
        $this->notifyOwnerOfJoin($group, $member);

        return ['success' => true, 'membership' => $membership->fresh(['equbGroup.package', 'member.user'])];
    }

    public function declineInvitation(EqubGroupInvitation $invitation): array
    {
        if (! $invitation->isPending()) {
            return ['success' => false, 'message' => 'This invitation is no longer valid.'];
        }

        $invitation->update([
            'status' => EqubInvitationStatus::Declined,
            'responded_at' => now(),
        ]);

        return ['success' => true, 'message' => 'Invitation declined.'];
    }

    /**
     * Owner removes someone before the Equb starts.
     */
    public function removeMember(EqubGroup $group, EqubMembership $membership): array
    {
        if ($group->status !== EqubGroupStatus::Registration) {
            return ['success' => false, 'message' => 'Members cannot be removed once the Equb has started.'];
        }

        if ($membership->role === EqubMembershipRole::Owner) {
            return ['success' => false, 'message' => 'The group creator cannot be removed.'];
        }

        return $this->membershipService->leaveEqub($membership);
    }

    /**
     * Kick off the Equb: freeze the head-count, the schedule and the winner plan.
     *
     * @return array{success: bool, message?: string, group?: EqubGroup, split_plan?: array}
     */
    public function start(EqubGroup $group): array
    {
        if ($group->status !== EqubGroupStatus::Registration) {
            return ['success' => false, 'message' => 'This Equb has already started.'];
        }

        if (! $group->isApproved()) {
            return ['success' => false, 'message' => 'An admin still has to approve this Equb.'];
        }

        $minMembers = (int) config('services.equb.group_min_members', 2);
        $memberCount = $group->activeMemberships()->count();

        if ($memberCount < $minMembers) {
            return ['success' => false, 'message' => "You need at least {$minMembers} members before starting."];
        }

        try {
            DB::transaction(function () use ($group) {
                // Existing service: sets running status, end date, pot per round.
                $this->groupService->initialize($group);
                $group->refresh();

                $group->update([
                    'is_locked' => true,
                    'registration_close_at' => now(),
                ]);

                $this->drawService->buildSplitPlan($group, persist: true);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to start group Equb {$group->id}: ".$e->getMessage());

            return ['success' => false, 'message' => 'Could not start this Equb. Please try again.'];
        }

        $group->refresh();
        $this->cancelPendingInvitations($group);
        $this->announceStart($group);

        return ['success' => true, 'group' => $group, 'split_plan' => $group->winner_split_plan ?? []];
    }

    /** Owner cancels the group before any money has moved. */
    public function cancel(EqubGroup $group): array
    {
        $hasPaid = EqubMembership::where('equb_group_id', $group->id)
            ->whereHas('payments', fn ($q) => $q->where('status', EqubPaymentStatus::Paid))
            ->exists();

        if ($hasPaid) {
            return ['success' => false, 'message' => 'This Equb has contributions already. Ask support to close it.'];
        }

        $group->update(['status' => EqubGroupStatus::Cancelled]);
        $this->cancelPendingInvitations($group);

        return ['success' => true, 'message' => 'The Equb was cancelled.'];
    }

    /**
     * Nudge everyone who is behind. Throttled per group.
     *
     * @param  array<int, array<string, mixed>>  $membersBehind  rows from EqubGroupLedgerService
     */
    public function remindUnpaid(EqubGroup $group, array $membersBehind): array
    {
        if ($membersBehind === []) {
            return ['success' => true, 'reminded' => 0, 'message' => 'Everyone is up to date.'];
        }

        $reminded = 0;

        foreach ($membersBehind as $row) {
            $membership = EqubMembership::with('member.user')->find($row['membership_id']);
            $phone = $membership?->member?->user?->phone;

            if (! $phone) {
                continue;
            }

            $amount = number_format((float) $row['outstanding_now'], 2);
            $text = "Reminder: your {$group->name} Equb contribution of {$amount} ETB is due. "
                .'Open the Niya app to pay.';

            $this->smsService->sendSms($phone, $text, null, $membership);

            if ($membership->member?->user?->id) {
                $this->fcmService->sendToUser(
                    $membership->member->user->id,
                    [
                        'type' => 'equb_group_payment_reminder',
                        'equb_group_id' => (string) $group->id,
                        'equb_membership_id' => (string) $membership->id,
                        'amount_due' => (string) $row['outstanding_now'],
                    ],
                    'Contribution due',
                    "{$amount} ETB is due for {$group->name}."
                );
            }

            $membership->update(['last_overdue_notified_at' => now()]);
            $reminded++;
        }

        return ['success' => true, 'reminded' => $reminded, 'message' => "Reminded {$reminded} member(s)."];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    public function canInvite(EqubGroup $group, Member $member): bool
    {
        if ($group->isOwnedBy($member->id)) {
            return true;
        }

        return $group->allow_member_invites && $this->alreadyInGroup($group, $member->id);
    }

    public function remainingCapacity(EqubGroup $group): int
    {
        if (! $group->max_members) {
            return PHP_INT_MAX;
        }

        $pending = $group->invitations()->pending()->count();

        return max(0, (int) $group->max_members - (int) $group->current_members_count - $pending);
    }

    protected function alreadyInGroup(EqubGroup $group, int $memberId): bool
    {
        return EqubMembership::where('equb_group_id', $group->id)
            ->where('member_id', $memberId)
            ->whereIn('status', [EqubMembershipStatus::Active, EqubMembershipStatus::Completed])
            ->exists();
    }

    protected function hasPendingInvite(EqubGroup $group, int $memberId): bool
    {
        return $group->invitations()->pending()->where('member_id', $memberId)->exists();
    }

    protected function resolveContribution(EqubPackage $package, $requested): ?float
    {
        if ($package->type === EqubPackageType::Flexible) {
            $amount = (float) $requested;
            $min = (float) $package->min_contribution_amount;
            $max = (float) $package->max_contribution_amount;

            if ($amount < $min || ($max > 0 && $amount > $max)) {
                return null;
            }

            return $amount;
        }

        return (float) $package->fixed_contribution_amount;
    }

    protected function validateWinnerConfig(WinnerSelectionMode $mode, array $data, int $maxMembers): ?string
    {
        if ($mode === WinnerSelectionMode::FixedSize) {
            $n = (int) ($data['winners_per_draw'] ?? 0);

            return $n >= 1 && $n <= $maxMembers
                ? null
                : "Winners per round must be between 1 and {$maxMembers}.";
        }

        if ($mode === WinnerSelectionMode::RandomSplit) {
            $min = (int) ($data['min_winners_per_draw'] ?? 0);
            $max = (int) ($data['max_winners_per_draw'] ?? 0);

            if ($min < 1 || $max < $min || $max > $maxMembers) {
                return "Winner group size must be between 1 and {$maxMembers}, and the smallest cannot exceed the largest.";
            }
        }

        return null;
    }

    protected function subscribeToTopic(Member $member, EqubGroup $group): void
    {
        $token = $member->user?->fcm_token;

        if ($token) {
            $this->fcmService->subscribeToTopic($token, FcmService::equbGroupTopic($group->id));
        }
    }

    protected function notifyInvitee(EqubGroup $group, Member $inviter, EqubGroupInvitation $invitation, ?Member $invitee): void
    {
        $inviterName = $inviter->full_name ?? 'A friend';
        $body = "{$inviterName} invited you to join the \"{$group->name}\" Equb.";

        if ($invitee?->user?->id) {
            $this->fcmService->sendToUser(
                $invitee->user->id,
                [
                    'type' => 'equb_group_invitation',
                    'equb_group_id' => (string) $group->id,
                    'invitation_id' => (string) $invitation->id,
                    'invite_code' => (string) $group->invite_code,
                ],
                'Equb invitation',
                $body
            );
        }

        if ($invitation->phone) {
            $this->smsService->sendSms(
                $invitation->phone,
                $body.' Open the Niya app or use code '.$group->invite_code.' to join.',
                null,
                $invitation
            );
        }
    }

    protected function notifyOwnerOfJoin(EqubGroup $group, Member $member): void
    {
        $ownerUserId = $group->owner?->user?->id;

        if (! $ownerUserId) {
            return;
        }

        $this->fcmService->sendToUser(
            $ownerUserId,
            [
                'type' => 'equb_group_member_joined',
                'equb_group_id' => (string) $group->id,
                'member_id' => (string) $member->id,
            ],
            'New member',
            ($member->full_name ?? 'A member')." joined {$group->name}."
        );
    }

    protected function cancelPendingInvitations(EqubGroup $group): void
    {
        $group->invitations()->pending()->update([
            'status' => EqubInvitationStatus::Cancelled,
            'responded_at' => now(),
        ]);
    }

    protected function announceStart(EqubGroup $group): void
    {
        $plan = implode(' + ', $group->winner_split_plan ?? []);

        $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($group->id),
            [
                'type' => 'equb_group_started',
                'equb_group_id' => (string) $group->id,
                'split_plan' => json_encode($group->winner_split_plan ?? []),
            ],
            'Equb started',
            $plan !== ''
                ? "{$group->name} has started. Winners per round: {$plan}."
                : "{$group->name} has started."
        );
    }
}

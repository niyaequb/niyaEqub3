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
        $parent = EqubGroup::with('package')->find($data['parent_equb_group_id'] ?? null);

        if (! $parent || $parent->isMemberCreated()) {
            return ['success' => false, 'message' => 'Choose an active Equb to join.'];
        }

        // A Group Equb can be opened while the parent is still being set up or
        // already running. Only a finished or cancelled Equb is refused.
        if (in_array($parent->status, [EqubGroupStatus::Completed, EqubGroupStatus::Cancelled], true)) {
            return [
                'success' => false,
                'message' => 'That Equb is '.($parent->status?->value ?? 'closed').', so no new groups can join it.',
            ];
        }

        // Money is never typed in. The contribution per person is inherited from
        // the parent Equb's package; every total follows the head-count.
        $amount = $parent->contributionPerPerson();

        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'That Equb has no contribution amount set. Ask an admin to check the package.',
            ];
        }

        $needsApproval = (bool) config('services.equb.group_requires_approval', true);
        $frequency = (int) ($parent->contribution_frequency_days ?: 1);
        $package = $parent->package;

        try {
            $group = DB::transaction(function () use ($owner, $parent, $package, $data, $amount, $needsApproval, $frequency) {
                $group = EqubGroup::create([
                    'equb_package_id' => $package?->id,
                    'owner_member_id' => $owner->id,
                    'parent_equb_group_id' => $parent->id,
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
                    'duration_type' => $parent->duration_type,
                    'duration_value' => $parent->duration_value,
                    'duration_unit' => $parent->duration_unit ?? \App\Enums\EqubDurationUnit::Days,
                    'terms_content' => $parent->terms_content ?? $package?->terms_content,
                    'registration_open_at' => now(),
                    'equb_start_date' => $parent->equb_start_date,
                    // No setup cap: the head-count is whoever accepts an invite.
                    // "How many members" is a draw-time question now.
                    'max_members' => null,
                    'status' => EqubGroupStatus::Registration,
                    'draw_type' => \App\Enums\EqubDrawType::Manual,
                    // Winners are drawn at the parent Equb level from the Equb
                    // Draws page, so the group carries no winner configuration.
                    'winner_selection_mode' => WinnerSelectionMode::Manual,
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

                return $group->fresh(['package', 'owner.user', 'parentGroup']);
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
     * Put members straight into the group, no invitation round-trip.
     *
     * This is the admin path: when a staff member builds a group in the panel
     * they are recording people who have already agreed, so the group should
     * show the real head-count immediately. The app still uses invite() so
     * members opt in themselves.
     *
     * @param  array<int>  $memberIds
     * @return array{success: bool, added: int, skipped: array<int, string>, message: string}
     */
    public function addMembersDirectly(EqubGroup $group, array $memberIds): array
    {
        $added = 0;
        $skipped = [];

        foreach (array_unique($memberIds) as $memberId) {
            $member = Member::with('user')->find($memberId);

            if (! $member) {
                $skipped[] = "Member #{$memberId} not found";

                continue;
            }

            if ($this->alreadyInGroup($group, $member->id)) {
                continue;
            }

            $joined = $this->membershipService->joinEqub($member->id, $group->id);

            if (! $joined['success']) {
                $skipped[] = ($member->full_name ?? "Member #{$memberId}").': '.($joined['message'] ?? 'could not join');

                continue;
            }

            $joined['membership']->update(['role' => EqubMembershipRole::Member]);

            // Any pending invitation is now moot.
            $group->invitations()
                ->pending()
                ->where('member_id', $member->id)
                ->update([
                    'status' => EqubInvitationStatus::Accepted,
                    'responded_at' => now(),
                ]);

            $this->subscribeToTopic($member, $group);
            $this->notifyAdded($group, $member);

            $added++;
        }

        $group->refresh();

        return [
            'success' => true,
            'added' => $added,
            'skipped' => $skipped,
            'message' => $added > 0
                ? "{$added} member(s) added."
                : 'No new members were added.',
        ];
    }

    protected function notifyAdded(EqubGroup $group, Member $member): void
    {
        $userId = $member->user?->id;

        if (! $userId) {
            return;
        }

        $this->safely(fn () => $this->fcmService->sendToUser(
            $userId,
            [
                'type' => 'equb_group_added',
                'equb_group_id' => (string) $group->id,
            ],
            'Added to an Equb group',
            "You are now part of \"{$group->name}\"."
        ));
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

        // Only an outright rejection blocks a start. A group still awaiting
        // review can run: the parent Equb is already vetted, and an admin can
        // reject it later if something is wrong.
        if ($group->moderation_status === EqubGroupModerationStatus::Rejected) {
            return [
                'success' => false,
                'message' => $group->rejection_reason ?: 'An admin has rejected this Equb.',
            ];
        }

        $minMembers = (int) config('services.equb.group_min_members', 2);
        $memberCount = $group->activeMemberships()->count();

        if ($memberCount < $minMembers) {
            return [
                'success' => false,
                'message' => "You need at least {$minMembers} members before starting. "
                    .'Right now there '.($memberCount === 1 ? 'is 1' : "are {$memberCount}").'.',
            ];
        }

        try {
            DB::transaction(function () use ($group) {
                // Existing service: sets running status, end date, pot per round.
                $this->groupService->initialize($group);
                $group->refresh();

                $group->update([
                    'is_locked' => true,
                    'registration_close_at' => now(),
                    // Approved implicitly by going live.
                    'moderation_status' => EqubGroupModerationStatus::Approved,
                    'approved_at' => $group->approved_at ?? now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to start group Equb {$group->id}: ".$e->getMessage());

            return ['success' => false, 'message' => 'Could not start this Equb: '.$e->getMessage()];
        }

        $group->refresh();
        $this->cancelPendingInvitations($group);
        $this->announceStart($group);

        return ['success' => true, 'group' => $group];
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
     * Nudge everyone who is behind.
     *
     * Push notification is the default channel: it is free, instant and lands
     * in the app where they can pay. SMS costs money per message, so it is
     * opt-in and only the admin panel turns it on.
     *
     * @param  array<int, array<string, mixed>>  $membersBehind  rows from EqubGroupLedgerService
     */
    public function remindUnpaid(EqubGroup $group, array $membersBehind, bool $alsoSendSms = false): array
    {
        if ($membersBehind === []) {
            return ['success' => true, 'reminded' => 0, 'message' => 'Everyone is up to date.'];
        }

        $reminded = 0;
        $noDevice = 0;

        foreach ($membersBehind as $row) {
            $membership = EqubMembership::with('member.user')->find($row['membership_id']);
            $user = $membership?->member?->user;

            if (! $user) {
                continue;
            }

            $amount = number_format((float) $row['outstanding_now'], 2);
            $sent = false;

            if ($user->fcm_token) {
                $this->safely(fn () => $this->fcmService->sendToUser(
                    $user->id,
                    [
                        'type' => 'equb_group_payment_reminder',
                        'equb_group_id' => (string) $group->id,
                        'equb_membership_id' => (string) $membership->id,
                        'amount_due' => (string) $row['outstanding_now'],
                    ],
                    'Contribution due',
                    "{$amount} ETB is due for {$group->name}."
                ));

                $sent = true;
            } else {
                $noDevice++;
            }

            // SMS is the admin's paid fallback, never the default.
            if ($alsoSendSms && $user->phone) {
                $this->safely(fn () => $this->smsService->sendSms(
                    $user->phone,
                    "Reminder: your {$group->name} Equb contribution of {$amount} ETB is due. "
                    .'Open the Niya app to pay.',
                    null,
                    $membership
                ));

                $sent = true;
            }

            if ($sent) {
                $membership->update(['last_overdue_notified_at' => now()]);
                $reminded++;
            }
        }

        $message = "Reminded {$reminded} member(s).";

        if ($noDevice > 0 && ! $alsoSendSms) {
            $message .= " {$noDevice} had no device registered for notifications.";
        }

        return ['success' => true, 'reminded' => $reminded, 'message' => $message];
    }

    /**
     * Notifications must never break the action that triggered them. FCM can be
     * unconfigured and an SMS gateway can be down; neither should fail a start,
     * an invite or a join.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Group Equb notification failed: '.$e->getMessage());
        }
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
            $this->safely(fn () => $this->fcmService->subscribeToTopic($token, FcmService::equbGroupTopic($group->id)));
        }
    }

    protected function notifyInvitee(EqubGroup $group, Member $inviter, EqubGroupInvitation $invitation, ?Member $invitee): void
    {
        $inviterName = $inviter->full_name ?? 'A friend';
        $body = "{$inviterName} invited you to join the \"{$group->name}\" Equb.";

        if ($invitee?->user?->id) {
            $this->safely(fn () => $this->fcmService->sendToUser(
                $invitee->user->id,
                [
                    'type' => 'equb_group_invitation',
                    'equb_group_id' => (string) $group->id,
                    'invitation_id' => (string) $invitation->id,
                    'invite_code' => (string) $group->invite_code,
                ],
                'Equb invitation',
                $body
            ));
        }

        // Only text people who are not on the platform yet; members already got
        // the push above.
        if ($invitation->phone && ! $invitee) {
            $this->safely(fn () => $this->smsService->sendSms(
                $invitation->phone,
                $body.' Open the Niya app or use code '.$group->invite_code.' to join.',
                null,
                $invitation
            ));
        }
    }

    protected function notifyOwnerOfJoin(EqubGroup $group, Member $member): void
    {
        $ownerUserId = $group->owner?->user?->id;

        if (! $ownerUserId) {
            return;
        }

        $this->safely(fn () => $this->fcmService->sendToUser(
            $ownerUserId,
            [
                'type' => 'equb_group_member_joined',
                'equb_group_id' => (string) $group->id,
                'member_id' => (string) $member->id,
            ],
            'New member',
            ($member->full_name ?? 'A member')." joined {$group->name}."
        ));
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
        $this->safely(fn () => $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($group->id),
            [
                'type' => 'equb_group_started',
                'equb_group_id' => (string) $group->id,
            ],
            'Equb started',
            "{$group->name} has started."
        ));
    }
}
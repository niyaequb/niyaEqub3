<?php

namespace App\Http\Controllers\Api\Member;

use App\Enums\EqubGroupStatus;
use App\Enums\EqubGroupVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\InviteEqubGroupMembersRequest;
use App\Http\Requests\Api\Member\RunGroupDrawRequest;
use App\Http\Requests\Api\Member\StoreMemberEqubGroupRequest;
use App\Http\Resources\Api\EqubGroupInvitationResource;
use App\Http\Resources\Api\GroupDrawResource;
use App\Http\Resources\Api\MemberEqubGroupResource;
use App\Models\EqubGroup;
use App\Models\EqubGroupInvitation;
use App\Models\EqubMembership;
use App\Models\Member;
use App\Services\EqubGroupLedgerService;
use App\Services\GroupDrawService;
use App\Services\MemberEqubGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Member-created Equb groups ("Group Equb"): create one, invite your circle,
 * watch who has paid, and run the winner-group draw.
 */
class MyEqubGroupController extends Controller
{
    public function __construct(
        protected MemberEqubGroupService $groups,
        protected EqubGroupLedgerService $ledger,
        protected GroupDrawService $draws,
    ) {}

    /**
     * Platform Equbs a member can build a Group Equb inside.
     * The contribution per person comes from here, never from the member.
     */
    public function joinableGroups(Request $request): JsonResponse
    {
        $groups = EqubGroup::query()
            ->whereNull('owner_member_id')
            ->where('visibility', EqubGroupVisibility::Public)
            ->whereIn('status', [
                EqubGroupStatus::Registration->value,
                EqubGroupStatus::Running->value,
            ])
            ->with('package:id,name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $groups->map(fn (EqubGroup $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'package_name' => $g->package?->name,
                'status' => $g->status?->value,
                'contribution_per_person' => round($g->contributionPerPerson(), 2),
                'contribution_frequency_days' => (int) $g->contribution_frequency_days,
                'rounds_total' => $g->totalRounds(),
                // Straight from equb_groups.terms_content on this Equb.
                'terms_content' => $g->termsContent(),
                'equb_start_date' => $g->equb_start_date?->toIso8601String(),
                'equb_end_date' => $g->equb_end_date?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Groups I created or was invited into. */
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        if (! $member) {
            return $this->missingMember();
        }

        $query = EqubGroup::query()
            ->where('visibility', EqubGroupVisibility::Private)
            ->where(function ($q) use ($member) {
                $q->where('owner_member_id', $member->id)
                    ->orWhereHas('memberships', fn ($m) => $m->where('member_id', $member->id));
            })
            ->with([
                'package',
                'owner.user',
                'memberships' => fn ($q) => $q->where('member_id', $member->id)->with(['payments', 'winsAsWinner']),
            ])
            ->withCount('draws');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('owned_only')) {
            $query->where('owner_member_id', $member->id);
        }

        $groups = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => MemberEqubGroupResource::collection($groups),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    public function store(StoreMemberEqubGroupRequest $request): JsonResponse
    {
        $member = $this->member($request);

        if (! $member) {
            return $this->missingMember();
        }

        $result = $this->groups->create($member, $request->validated());

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        $group = $result['group'];

        // Optional: invite people straight from the create screen.
        $invited = $this->groups->invite(
            $group,
            $member,
            $request->input('invite_member_ids', []),
            $request->input('invite_phones', []),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Your Equb group is ready. Invite your members to get started.',
            'data' => new MemberEqubGroupResource($group->load(['package', 'owner.user'])),
            'invitations' => ['sent' => $invited['invited'], 'skipped' => $invited['skipped']],
        ], 201);
    }

    public function show(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $this->canView($equbGroup, $member)) {
            return $this->forbidden();
        }

        $equbGroup->load([
            'package',
            'owner.user',
            'memberships' => fn ($q) => $q->where('member_id', $member->id)->with(['payments', 'winsAsWinner']),
        ])->loadCount('draws');

        return response()->json([
            'status' => 'success',
            'data' => new MemberEqubGroupResource($equbGroup),
        ]);
    }

    /** The paid / unpaid dashboard for the whole circle. */
    public function ledger(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $this->canView($equbGroup, $member)) {
            return $this->forbidden();
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->ledger->forGroup($equbGroup),
        ]);
    }

    public function invite(InviteEqubGroupMembersRequest $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $member || ! $this->groups->canInvite($equbGroup, $member)) {
            return $this->forbidden('Only the group creator can invite members to this Equb.');
        }

        $result = $this->groups->invite(
            $equbGroup,
            $member,
            $request->input('member_ids', []),
            $request->input('phones', []),
            $request->input('message'),
        );

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => ['sent' => $result['invited'], 'skipped' => $result['skipped']],
        ], $result['success'] ? 200 : 422);
    }

    /** Invitations the owner has sent for this group. */
    public function invitations(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden();
        }

        $invitations = $equbGroup->invitations()
            ->with(['member.user', 'invitedBy.user', 'equbGroup.package'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => EqubGroupInvitationResource::collection($invitations),
        ]);
    }

    public function removeMember(Request $request, EqubGroup $equbGroup, EqubMembership $equbMembership): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can remove members.');
        }

        if ((int) $equbMembership->equb_group_id !== (int) $equbGroup->id) {
            return $this->forbidden();
        }

        $result = $this->groups->removeMember($equbGroup, $equbMembership);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Member removed.',
        ], $result['success'] ? 200 : 422);
    }

    /** Preview the winner-group split before starting. */
    public function splitPlan(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $this->canView($equbGroup, $member)) {
            return $this->forbidden();
        }

        $plan = $equbGroup->winner_split_plan;

        // Before the Equb starts the plan is only a preview and is re-rolled
        // on request, because the head-count can still change.
        if (! $plan || $request->boolean('regenerate')) {
            $plan = $this->draws->buildSplitPlan($equbGroup, persist: false);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'members_count' => $equbGroup->activeMemberships()->count(),
                'winner_selection_mode' => $equbGroup->winner_selection_mode?->value,
                'split_plan' => $plan,
                'rounds' => count($plan),
                'is_final' => $equbGroup->status !== EqubGroupStatus::Registration,
            ],
        ]);
    }

    public function start(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can start this Equb.');
        }

        $result = $this->groups->start($equbGroup);

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'The Equb has started.',
            'data' => new MemberEqubGroupResource($result['group']->load(['package', 'owner.user'])),
            'split_plan' => $result['split_plan'],
        ]);
    }

    public function cancel(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can cancel this Equb.');
        }

        $result = $this->groups->cancel($equbGroup);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    /** Draw history: every round with its full winner group. */
    public function draws(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $this->canView($equbGroup, $member)) {
            return $this->forbidden();
        }

        $draws = $equbGroup->draws()
            ->with(['winners.membership.member.user', 'winnerMembership.member', 'equbGroup'])
            ->orderByDesc('draw_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => GroupDrawResource::collection($draws),
        ]);
    }

    /** Run one round: automatic winner group, or a hand-picked one. */
    public function runDraw(RunGroupDrawRequest $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can run the draw.');
        }

        $lock = Cache::lock("equb-group-draw-{$equbGroup->id}", 60);

        if (! $lock->get()) {
            return response()->json(['status' => 'error', 'message' => 'A draw is already running for this Equb.'], 409);
        }

        try {
            $result = $this->draws->runRound(
                $equbGroup,
                $request->user()?->id,
                $request->input('membership_ids', []),
                $request->input('winners_count'),
            );
        } finally {
            $lock->release();
        }

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'The winners for this round have been drawn.',
            'data' => new GroupDrawResource($result['draw']),
        ], 201);
    }

    /** Text everyone who is behind on contributions. Once per day per group. */
    public function remindUnpaid(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can send reminders.');
        }

        $key = "equb-group-remind-{$equbGroup->id}";

        if (Cache::has($key)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reminders were already sent today. Try again tomorrow.',
            ], 429);
        }

        $result = $this->groups->remindUnpaid($equbGroup, $this->ledger->membersBehind($equbGroup));

        if (($result['reminded'] ?? 0) > 0) {
            Cache::put($key, true, now()->addDay());
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => ['reminded' => $result['reminded']],
        ]);
    }

    /** Ask to join a group using a shared invite code. */
    public function joinByCode(Request $request): JsonResponse
    {
        $request->validate(['invite_code' => ['required', 'string', 'max:12']]);

        $member = $this->member($request);

        if (! $member) {
            return $this->missingMember();
        }

        $group = EqubGroup::query()
            ->where('invite_code', strtoupper(trim($request->input('invite_code'))))
            ->where('visibility', EqubGroupVisibility::Private)
            ->with(['owner.user'])
            ->first();

        if (! $group) {
            return response()->json([
                'status' => 'error',
                'message' => 'No Equb found for that code. Check it and try again.',
            ], 404);
        }

        $result = $this->groups->joinWithCode($group, $member);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => ['equb_group_id' => $group->id, 'name' => $group->name],
        ], $result['success'] ? 201 : 422);
    }

    /** Pending join requests for a group the caller owns. */
    public function joinRequests(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden();
        }

        $requests = $equbGroup->invitations()
            ->requests()
            ->pending()
            ->with(['member.user', 'equbGroup.package'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => EqubGroupInvitationResource::collection($requests),
        ]);
    }

    /** Owner approves or declines a request. */
    public function respondToRequest(Request $request, EqubGroup $equbGroup, EqubGroupInvitation $invitation): JsonResponse
    {
        $member = $this->member($request);

        if (! $equbGroup->isOwnedBy($member?->id)) {
            return $this->forbidden('Only the group creator can respond to requests.');
        }

        if ((int) $invitation->equb_group_id !== (int) $equbGroup->id) {
            return $this->forbidden();
        }

        $approve = $request->boolean('approve', true);

        $result = $approve
            ? $this->groups->approveRequest($invitation)
            : $this->groups->rejectRequest($invitation);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    /** Preview a group from a shared invite code, before asking to join. */
    public function findByInviteCode(Request $request, string $code): JsonResponse
    {
        $group = EqubGroup::query()
            ->where('invite_code', strtoupper(trim($code)))
            ->where('visibility', EqubGroupVisibility::Private)
            ->with(['package', 'owner.user', 'parentGroup'])
            ->withCount(['memberships as active_members' => fn ($q) => $q->where('status', \App\Enums\EqubMembershipStatus::Active)])
            ->first();

        if (! $group) {
            return response()->json([
                'status' => 'error',
                'message' => 'No Equb found for that code. Check it and try again.',
            ], 404);
        }

        $member = $this->member($request);

        $pending = $member
            ? $group->invitations()->pending()->where('member_id', $member->id)->first()
            : null;

        $alreadyIn = $member && EqubMembership::where('equb_group_id', $group->id)
            ->where('member_id', $member->id)
            ->exists();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'invite_code' => $group->invite_code,
                'owner_name' => $group->owner?->full_name,
                'equb_name' => $group->parentGroup?->name ?? $group->package?->name,
                'members_count' => (int) $group->active_members,
                'contribution_per_person' => round($group->contributionPerPerson(), 2),
                'contribution_frequency_days' => (int) $group->contribution_frequency_days,
                'rounds_total' => $group->totalRounds(),
                // The parent Equb's terms, not a copy held on this group.
                'terms_content' => $group->termsContent(),
                'already_member' => (bool) $alreadyIn,
                'request_pending' => $pending !== null,
            ],
        ]);
    }

    // -----------------------------------------------------------------

    protected function member(Request $request): ?Member
    {
        return $request->user()?->member;
    }

    protected function canView(EqubGroup $group, ?Member $member): bool
    {
        if (! $member) {
            return false;
        }

        if ($group->isOwnedBy($member->id)) {
            return true;
        }

        return EqubMembership::where('equb_group_id', $group->id)
            ->where('member_id', $member->id)
            ->exists();
    }

    protected function missingMember(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
    }

    protected function forbidden(string $message = 'You do not have access to this Equb.'): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }
}

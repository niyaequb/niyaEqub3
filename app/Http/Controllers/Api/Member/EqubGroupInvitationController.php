<?php

namespace App\Http\Controllers\Api\Member;

use App\Enums\EqubInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EqubGroupInvitationResource;
use App\Http\Resources\Api\EqubMembershipResource;
use App\Models\EqubGroupInvitation;
use App\Services\MemberEqubGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubGroupInvitationController extends Controller
{
    public function __construct(protected MemberEqubGroupService $groups) {}

    /** Invitations waiting for me. */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $phone = $request->user()?->phone;

        $invitations = EqubGroupInvitation::query()
            ->pending()
            ->where(function ($q) use ($member, $phone) {
                $q->where('member_id', $member->id);

                if ($phone) {
                    $q->orWhere(fn ($inner) => $inner->whereNull('member_id')->where('phone', $phone));
                }
            })
            ->with(['equbGroup.package', 'invitedBy.user', 'member'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => EqubGroupInvitationResource::collection($invitations),
        ]);
    }

    public function accept(Request $request, EqubGroupInvitation $invitation): JsonResponse
    {
        $member = $request->user()?->member;

        if (! $member || ! $this->isForMe($invitation, $request)) {
            return response()->json(['status' => 'error', 'message' => 'This invitation is not for you.'], 403);
        }

        $result = $this->groups->acceptInvitation($invitation, $member);

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'You have joined the Equb.',
            'data' => new EqubMembershipResource($result['membership']),
        ], 201);
    }

    public function decline(Request $request, EqubGroupInvitation $invitation): JsonResponse
    {
        if (! $this->isForMe($invitation, $request)) {
            return response()->json(['status' => 'error', 'message' => 'This invitation is not for you.'], 403);
        }

        $result = $this->groups->declineInvitation($invitation);

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    /** The inviter withdraws an invitation. */
    public function cancel(Request $request, EqubGroupInvitation $invitation): JsonResponse
    {
        $member = $request->user()?->member;

        $isOwner = $invitation->equbGroup?->isOwnedBy($member?->id);
        $isInviter = (int) $invitation->invited_by_member_id === (int) $member?->id;

        if (! $isOwner && ! $isInviter) {
            return response()->json(['status' => 'error', 'message' => 'You cannot cancel this invitation.'], 403);
        }

        $invitation->update([
            'status' => EqubInvitationStatus::Cancelled,
            'responded_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Invitation cancelled.']);
    }

    protected function isForMe(EqubGroupInvitation $invitation, Request $request): bool
    {
        $member = $request->user()?->member;

        if ($member && (int) $invitation->member_id === (int) $member->id) {
            return true;
        }

        return $invitation->member_id === null
            && $invitation->phone !== null
            && $invitation->phone === $request->user()?->phone;
    }
}

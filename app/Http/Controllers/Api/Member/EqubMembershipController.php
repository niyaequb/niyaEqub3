<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\JoinEqubRequest;
use App\Http\Resources\Api\EqubMembershipResource;
use App\Models\EqubMembership;
use App\Services\EqubMembershipService;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubMembershipController extends Controller
{
    /**
     * List current user's Equb memberships.
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $query = EqubMembership::query()
            ->where('member_id', $member->id)
            ->with(['equbGroup.package', 'member.user', 'payments', 'winsAsWinner']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $memberships = $query->latest('join_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data'   => EqubMembershipResource::collection($memberships),
            'meta'   => [
                'current_page' => $memberships->currentPage(),
                'last_page'    => $memberships->lastPage(),
                'per_page'     => $memberships->perPage(),
                'total'        => $memberships->total(),
            ],
        ]);
    }

    /**
     * Show one of my memberships.
     */
    public function show(Request $request, EqubMembership $equbMembership): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }
        if ((int) $equbMembership->member_id !== (int) $member->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $equbMembership->load(['equbGroup.package', 'member.user', 'payments']);

        return response()->json([
            'status' => 'success',
            'data'   => new EqubMembershipResource($equbMembership),
        ]);
    }

    /**
     * Join an Equb group (create membership).
     * After joining, subscribe the device's FCM token to the group draw topic
     * so the member automatically receives draw push notifications.
     */
    public function store(JoinEqubRequest $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $service = app(EqubMembershipService::class);
        $groupId = (int) $request->input('equb_group_id');
        $result  = $service->joinEqub($member->id, $groupId);

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        // Subscribe the device to the group's FCM draw topic (equb_group_{id})
        $fcmToken = $request->user()?->fcm_token;
        if ($fcmToken) {
            $topic = FcmService::equbGroupTopic($groupId);
            app(FcmService::class)->subscribeToTopic($fcmToken, $topic);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'You have joined the Equb successfully.',
            'data'    => new EqubMembershipResource($result['membership']),
        ], 201);
    }

    /**
     * Leave an Equb group if no payments have been made.
     */
    public function leave(Request $request, EqubMembership $equbMembership): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        // Authorize: membership must belong to the current authenticated member
        if ((int) $equbMembership->member_id !== (int) $member->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $service = app(EqubMembershipService::class);
        $result = $service->leaveEqub($equbMembership);

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status'  => 'success',
            'message' => $result['message'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EqubGroupModerationStatus;
use App\Enums\EqubGroupVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\RunGroupDrawRequest;
use App\Http\Resources\Api\GroupDrawResource;
use App\Http\Resources\Api\MemberEqubGroupResource;
use App\Models\EqubGroup;
use App\Services\EqubGroupLedgerService;
use App\Services\FcmService;
use App\Services\GroupDrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin oversight of member-created Equb groups.
 */
class MemberEqubGroupController extends Controller
{
    public function __construct(
        protected EqubGroupLedgerService $ledger,
        protected GroupDrawService $draws,
        protected FcmService $fcm,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = EqubGroup::query()
            ->where('visibility', EqubGroupVisibility::Private)
            ->with(['package', 'owner.user'])
            ->withCount(['memberships', 'draws']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', $request->input('moderation_status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('invite_code', 'like', "%{$search}%");
            });
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

    public function show(EqubGroup $equbGroup): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new MemberEqubGroupResource($equbGroup->load(['package', 'owner.user'])),
        ]);
    }

    public function ledger(EqubGroup $equbGroup): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->ledger->forGroup($equbGroup),
        ]);
    }

    public function approve(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->update([
            'moderation_status' => EqubGroupModerationStatus::Approved,
            'approved_at' => now(),
            'approved_by_admin_id' => $request->user()->id,
            'rejection_reason' => null,
        ]);

        $this->notifyOwner($equbGroup, 'Equb approved', "{$equbGroup->name} is approved. You can start it once your members join.");

        return response()->json([
            'status' => 'success',
            'message' => 'Group approved.',
            'data' => new MemberEqubGroupResource($equbGroup->fresh(['package', 'owner.user'])),
        ]);
    }

    public function reject(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $equbGroup->update([
            'moderation_status' => EqubGroupModerationStatus::Rejected,
            'rejection_reason' => $request->input('reason'),
            'approved_by_admin_id' => $request->user()->id,
        ]);

        $this->notifyOwner($equbGroup, 'Equb not approved', $request->input('reason'));

        return response()->json([
            'status' => 'success',
            'message' => 'Group rejected.',
            'data' => new MemberEqubGroupResource($equbGroup->fresh(['package', 'owner.user'])),
        ]);
    }

    /** Admin runs a round on the group's behalf (support case). */
    public function runDraw(RunGroupDrawRequest $request, EqubGroup $equbGroup): JsonResponse
    {
        $result = $this->draws->runRound(
            $equbGroup,
            $request->user()->id,
            $request->input('membership_ids', []),
            $request->input('winners_count'),
        );

        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Round completed.',
            'data' => new GroupDrawResource($result['draw']),
        ], 201);
    }

    protected function notifyOwner(EqubGroup $group, string $title, string $body): void
    {
        $userId = $group->owner?->user?->id;

        if (! $userId) {
            return;
        }

        $this->fcm->sendToUser(
            $userId,
            [
                'type' => 'equb_group_moderation',
                'equb_group_id' => (string) $group->id,
                'moderation_status' => (string) $group->moderation_status?->value,
            ],
            $title,
            $body
        );
    }
}

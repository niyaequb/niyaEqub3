<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEqubMembershipRequest;
use App\Http\Requests\Admin\UpdateEqubMembershipRequest;
use App\Http\Resources\Api\EqubMembershipResource;
use App\Models\EqubMembership;
use App\Services\EqubMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EqubMembership::query()->with(['equbGroup.package', 'member.user']);
        if ($request->filled('equb_group_id')) {
            $query->where('equb_group_id', $request->input('equb_group_id'));
        }
        if ($request->filled('member_id')) {
            $query->where('member_id', $request->input('member_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $memberships = $query->latest('join_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubMembershipResource::collection($memberships),
            'meta' => ['current_page' => $memberships->currentPage(), 'last_page' => $memberships->lastPage(), 'per_page' => $memberships->perPage(), 'total' => $memberships->total()],
        ]);
    }

    public function show(EqubMembership $equbMembership): JsonResponse
    {
        $equbMembership->load(['equbGroup.package', 'member.user']);

        return response()->json(['status' => 'success', 'data' => new EqubMembershipResource($equbMembership)]);
    }

    public function store(StoreEqubMembershipRequest $request): JsonResponse
    {
        $service = app(EqubMembershipService::class);
        $result = $service->joinEqub(
            (int) $request->input('member_id'),
            (int) $request->input('equb_group_id'),
            (float) $request->input('contribution_amount'),
            $request->input('contribution_frequency_days') ? (int) $request->input('contribution_frequency_days') : null
        );
        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Member joined Equb successfully.',
            'data' => new EqubMembershipResource($result['membership']),
        ], 201);
    }

    public function update(UpdateEqubMembershipRequest $request, EqubMembership $equbMembership): JsonResponse
    {
        $equbMembership->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Equb membership updated successfully.',
            'data' => new EqubMembershipResource($equbMembership->fresh()->load(['equbGroup.package', 'member.user'])),
        ]);
    }

    public function destroy(EqubMembership $equbMembership): JsonResponse
    {
        $equbMembership->equbGroup->decrement('current_members_count');
        $equbMembership->delete();

        return response()->json(['status' => 'success', 'message' => 'Equb membership deleted successfully.']);
    }
}

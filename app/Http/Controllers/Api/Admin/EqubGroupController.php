<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EqubGroupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEqubGroupRequest;
use App\Http\Requests\Admin\UpdateEqubGroupRequest;
use App\Http\Resources\Api\EqubGroupResource;
use App\Models\EqubGroup;
use App\Services\EqubDrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EqubGroup::query()->with('package');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('draw_type')) {
            $query->where('draw_type', $request->input('draw_type'));
        }
        $groups = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubGroupResource::collection($groups),
            'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'per_page' => $groups->perPage(), 'total' => $groups->total()],
        ]);
    }

    public function show(EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->load('package');

        return response()->json(['status' => 'success', 'data' => new EqubGroupResource($equbGroup)]);
    }

    public function store(StoreEqubGroupRequest $request): JsonResponse
    {
        $group = EqubGroup::create($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Equb group created successfully.',
            'data' => new EqubGroupResource($group->load('package')),
        ], 201);
    }

    public function update(UpdateEqubGroupRequest $request, EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Equb group updated successfully.',
            'data' => new EqubGroupResource($equbGroup->fresh()->load('package')),
        ]);
    }

    public function destroy(EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->delete();

        return response()->json(['status' => 'success', 'message' => 'Equb group deleted successfully.']);
    }

    public function openRegistration(EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->update(['status' => EqubGroupStatus::Registration]);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration opened.',
            'data' => new EqubGroupResource($equbGroup->fresh()->load('package')),
        ]);
    }

    public function closeRegistration(EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->update(['status' => EqubGroupStatus::Draft, 'registration_close_at' => $equbGroup->registration_close_at ?? now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration closed.',
            'data' => new EqubGroupResource($equbGroup->fresh()->load('package')),
        ]);
    }

    public function startEqub(EqubGroup $equbGroup): JsonResponse
    {
        $equbGroup->update(['status' => EqubGroupStatus::Running, 'equb_start_date' => $equbGroup->equb_start_date ?? now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Equb started.',
            'data' => new EqubGroupResource($equbGroup->fresh()->load('package')),
        ]);
    }

    public function runDraw(EqubGroup $equbGroup): JsonResponse
    {
        $result = app(EqubDrawService::class)->runDraw($equbGroup->id, auth()->id());
        if (! $result['success']) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'Draw failed.'], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Draw completed.',
            'data' => new \App\Http\Resources\Api\EqubDrawResource($result['draw']->load(['winnerMembership.member.user', 'equbGroup.package'])),
        ]);
    }
}

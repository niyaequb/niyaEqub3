<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EqubDrawResource;
use App\Models\EqubDraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubDrawController extends Controller
{
    /**
     * List draws (for my groups or by equb_group_id).
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $query = EqubDraw::query()->with(['equbGroup.package', 'winnerMembership.member.user']);

        if ($request->filled('equb_group_id')) {
            $query->where('equb_group_id', $request->input('equb_group_id'));
        }
        if ($request->boolean('my_groups_only')) {
            $query->whereHas('equbGroup.memberships', fn ($q) => $q->where('member_id', $member->id));
        }

        $draws = $query->latest('draw_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubDrawResource::collection($draws),
            'meta' => [
                'current_page' => $draws->currentPage(),
                'last_page' => $draws->lastPage(),
                'per_page' => $draws->perPage(),
                'total' => $draws->total(),
            ],
        ]);
    }

    /**
     * Show a single draw.
     */
    public function show(Request $request, EqubDraw $equbDraw): JsonResponse
    {
        $equbDraw->load(['equbGroup.package', 'winnerMembership.member.user']);

        return response()->json([
            'status' => 'success',
            'data' => new EqubDrawResource($equbDraw),
        ]);
    }
}

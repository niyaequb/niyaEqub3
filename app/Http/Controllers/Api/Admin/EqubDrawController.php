<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EqubDrawResource;
use App\Models\EqubDraw;
use App\Models\EqubGroup;
use App\Services\EqubDrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubDrawController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EqubDraw::query()->with(['equbGroup.package', 'winnerMembership.member.user', 'executedBy']);

        if ($request->filled('equb_group_id')) {
            $query->where('equb_group_id', $request->input('equb_group_id'));
        }

        $draws = $query->latest('draw_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubDrawResource::collection($draws),
            'meta' => ['current_page' => $draws->currentPage(), 'last_page' => $draws->lastPage(), 'per_page' => $draws->perPage(), 'total' => $draws->total()],
        ]);
    }

    public function show(EqubDraw $equbDraw): JsonResponse
    {
        $equbDraw->load(['equbGroup.package', 'winnerMembership.member.user', 'executedBy']);

        return response()->json([
            'status' => 'success',
            'data' => new EqubDrawResource($equbDraw),
        ]);
    }

    public function runDraw(EqubGroup $equbGroup): JsonResponse
    {
        $result = app(EqubDrawService::class)->runDraw($equbGroup->id, auth()->id());

        if (! $result['success']) {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Draw failed.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Draw completed.',
            'data' => new EqubDrawResource($result['draw']->load(['winnerMembership.member.user', 'equbGroup.package'])),
        ]);
    }
}

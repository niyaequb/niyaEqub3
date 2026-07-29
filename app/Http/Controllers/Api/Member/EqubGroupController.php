<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EqubGroupResource;
use App\Models\EqubGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EqubGroupController extends Controller
{
    /**
     * List Equb groups (optionally filter by status: registration, running).
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        $query = EqubGroup::query()->with(['package', 'memberships' => function ($q) use ($member) {
            $q->where('member_id', $member?->id)->with(['payments', 'winsAsWinner']);
        }]);

        // Member-created groups are private to their circle. They are served by
        // MyEqubGroupController and must never appear in the public browse list.
        $query->where('visibility', \App\Enums\EqubGroupVisibility::Public);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('equb_package_id')) {
            $query->where('equb_package_id', $request->input('equb_package_id'));
        }
        if ($request->boolean('open_for_registration')) {
            // $query->where('status', 'registration')
            //     ->where('registration_open_at', '<=', now())
            //     ->where(function ($q) {
            //         $q->whereNull('registration_close_at')
            //             ->orWhere('registration_close_at', '>=', now());
            //     })
                // ->where(function ($q) {
                //     $q->whereNull('max_members')
                //         ->orWhereColumn('current_members_count', '<', 'max_members');
                // })
                ;
        }

        // filter ekub member already joined groups
        $query->whereDoesntHave('memberships', function ($q) use ($member) {
            $q->where('member_id', $member?->id);
        });

        $groups = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubGroupResource::collection($groups),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    /**
     * Show a single group with package.
     */
    public function show(Request $request, EqubGroup $equbGroup): JsonResponse
    {
        if ($equbGroup->visibility === \App\Enums\EqubGroupVisibility::Private) {
            return response()->json(['status' => 'error', 'message' => 'Equb group not found.'], 404);
        }

        $member = $request->user()?->member;
        $equbGroup->load(['package', 'memberships' => function ($q) use ($member) {
            $q->where('member_id', $member?->id)->with(['payments', 'winsAsWinner']);
        }]);

        return response()->json([
            'status' => 'success',
            'data' => new EqubGroupResource($equbGroup),
        ]);
    }
}

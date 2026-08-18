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
            // Same rule as show(): own membership plus any places held for
            // others, so a card can quote what this member really owes.
            $q->where(function ($sub) use ($member) {
                $sub->where('member_id', $member?->id)
                    ->orWhere('sponsor_member_id', $member?->id);
            })
                ->with(['payments', 'winsAsWinner', 'sponsor'])
                ->orderByRaw('member_id is null')
                ->orderBy('id');
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
        // Private groups are invisible to outsiders, but a member of one must
        // still be able to open it — the payment screens load the group here.
        if ($equbGroup->visibility === \App\Enums\EqubGroupVisibility::Private) {
            $member = $request->user()?->member;

            $belongs = $member && (
                $equbGroup->isOwnedBy($member->id)
                || \App\Models\EqubMembership::where('equb_group_id', $equbGroup->id)
                    ->where('member_id', $member->id)
                    ->exists()
            );

            if (! $belongs) {
                return response()->json(['status' => 'error', 'message' => 'Equb group not found.'], 404);
            }
        }

        $member = $request->user()?->member;
        $equbGroup->load(['package', 'memberships' => function ($q) use ($member) {
            // The caller's own membership *and* every place they hold for
            // someone else in this group ("My Responsibility People").
            //
            // Filtering on member_id alone is what made the payment screen
            // quote one contribution when the member was actually liable for
            // several: the seats they pay for were never sent down, so the
            // screen had no way to know they existed.
            $q->where(function ($sub) use ($member) {
                $sub->where('member_id', $member?->id)
                    ->orWhere('sponsor_member_id', $member?->id);
            })
                ->with(['payments', 'winsAsWinner', 'sponsor'])
                // The member's own place first, so a client taking the first
                // row still gets the row it expects.
                ->orderByRaw('member_id is null')
                ->orderBy('id');
        }]);

        return response()->json([
            'status' => 'success',
            'data' => new EqubGroupResource($equbGroup),
        ]);
    }
}

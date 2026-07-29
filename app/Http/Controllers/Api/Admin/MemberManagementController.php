<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RegisteredVia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMemberRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $members = Member::query()
            ->with(['user', 'agent.user'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'members' => $members,
        ]);
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'member' => $member->load(['user', 'agent.user']),
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $member = DB::transaction(function () use ($request) {
            $agentId = $request->input('agent_id');
            $agent = null;

            if ($agentId) {
                $agent = Agent::query()->whereKey($agentId)->first();
            }

            if (! $agent && $request->filled('referral_code')) {
                $agent = Agent::query()
                    ->where('referral_code', $request->input('referral_code'))
                    ->where('is_active', true)
                    ->first();
                $agentId = $agent?->id;
            }

            $user = User::create([
                'name' => $request->input('full_name'),
                'phone' => $request->input('phone'),
                'password' => Hash::make($request->input('password')),
                'type' => 'member',
                'phone_verified_at' => null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            return $user->member()->create([
                'full_name' => $request->input('full_name'),
                'gender' => $request->input('gender'),
                'date_of_birth' => $request->input('date_of_birth'),
                'address' => $request->input('address'),
                'agent_id' => $agentId,
                'registered_via' => $agentId ? RegisteredVia::Agent : RegisteredVia::Direct,
                'referral_code_used' => $agent?->referral_code,
                'registered_at' => now(),
            ])->load(['user', 'agent.user']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Member created successfully.',
            'member' => $member,
        ], 201);
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        $member = DB::transaction(function () use ($request, $member) {
            $user = $member->user;

            if ($request->filled('full_name')) {
                $member->full_name = $request->input('full_name');
                $user->name = $request->input('full_name');
            }

            if ($request->filled('phone')) {
                $user->phone = $request->input('phone');
            }

            if ($request->has('is_active')) {
                $user->is_active = $request->boolean('is_active');
            }

            $member->gender = $request->input('gender', $member->gender);
            $member->date_of_birth = $request->input('date_of_birth', $member->date_of_birth);
            $member->address = $request->input('address', $member->address);

            if ($request->has('agent_id')) {
                $member->agent_id = $request->input('agent_id');
                $agent = $member->agent_id
                    ? Agent::query()->whereKey($member->agent_id)->first()
                    : null;
                $member->registered_via = $agent ? RegisteredVia::Agent : RegisteredVia::Direct;
                $member->referral_code_used = $agent?->referral_code;
            }

            $user->save();
            $member->save();

            return $member->load(['user', 'agent.user']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Member updated successfully.',
            'member' => $member,
        ]);
    }

    public function destroy(Member $member): JsonResponse
    {
        $user = $member->user;
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Member deleted successfully.',
        ]);
    }
}

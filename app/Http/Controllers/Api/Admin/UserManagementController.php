<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RegisteredVia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $query = User::query()->with('member');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $users = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'users' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'user' => $user->load('member'),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $user->update([
            'is_active' => $request->boolean('is_active'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User status updated successfully.',
            'user' => $user->load('member'),
        ]);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $type = $request->string('type')->toString();

        $user = DB::transaction(function () use ($request, $user, $type) {
            $fullName = $request->input('full_name');

            $user->update([
                'type' => $type,
                'name' => $fullName ?? $user->name,
            ]);

            if ($type === 'member') {
                $member = $user->member;

                if ($member) {
                    if ($fullName) {
                        $member->update(['full_name' => $fullName]);
                    }
                } else {
                    $user->member()->create([
                        'full_name' => $fullName ?? $user->name,
                        'registered_via' => RegisteredVia::Direct,
                        'referral_code_used' => null,
                        'registered_at' => now(),
                    ]);
                }
            } elseif ($user->member) {
                $user->member->delete();
            }

            if ($type === 'agent' && ! $user->agentProfile) {
                Agent::query()->create([
                    'user_id' => $user->id,
                    'referral_code' => $this->generateReferralCode(),
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            } elseif ($type !== 'agent' && $user->agentProfile) {
                $user->agentProfile->delete();
            }

            return $user->load('member');
        });

        return response()->json([
            'status' => 'success',
            'message' => 'User role updated successfully.',
            'user' => $user,
        ]);
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully.',
            'user' => $user->load('member'),
        ]);
    }

    private function generateReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Agent::query()->where('referral_code', $code)->exists());

        return $code;
    }
}

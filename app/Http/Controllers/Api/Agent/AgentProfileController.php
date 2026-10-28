<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The agent's own profile and payout (bank) details.
 */
class AgentProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $agent = $user?->agentProfile;

        if (! $agent) {
            return response()->json(['status' => 'error', 'message' => 'Agent profile not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($user, $agent),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $agent = $user?->agentProfile;

        if (! $agent) {
            return response()->json(['status' => 'error', 'message' => 'Agent profile not found.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Bank details are saved as a set: all three or none.
            'bank_name' => ['sometimes', 'required_with:account_number,account_holder_name', 'nullable', 'string', 'max:255'],
            'account_number' => ['sometimes', 'required_with:bank_name,account_holder_name', 'nullable', 'string', 'regex:/^[0-9 +\-]{5,30}$/'],
            'account_holder_name' => ['sometimes', 'required_with:bank_name,account_number', 'nullable', 'string', 'min:3', 'max:255'],
        ], [
            'account_number.regex' => 'Account number may only contain digits (5 to 30).',
        ]);

        if (isset($data['account_number'])) {
            $data['account_number'] = preg_replace('/\s+/', '', (string) $data['account_number']);
        }

        $user->update(array_intersect_key($data, array_flip(['name', 'email', 'city'])));
        $agent->update(array_intersect_key($data, array_flip(['bank_name', 'account_number', 'account_holder_name'])));

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated.',
            'data' => $this->payload($user->fresh(), $agent->fresh()),
        ]);
    }

    private function payload($user, $agent): array
    {
        return [
            'agent_id' => $agent->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'city' => $user->city,
            'profile_picture_url' => $user->profile_picture_url,
            'referral_code' => $agent->referral_code,
            'is_active' => (bool) $agent->is_active,
            'joined_at' => optional($agent->joined_at)->toIso8601String(),
            'bank_name' => $agent->bank_name,
            'account_number' => $agent->account_number,
            'account_holder_name' => $agent->account_holder_name,
        ];
    }
}

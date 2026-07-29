<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AgentResource;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;

class AgentInfoController extends Controller
{
    public function show(string $referralCode): JsonResponse
    {
        $agent = Agent::query()
            ->where('referral_code', $referralCode)
            ->where('is_active', true)
            ->with('user')
            ->first();

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'agent' => new AgentResource($agent),
        ]);
    }
}

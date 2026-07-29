<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AgentCommissionResource;
use App\Models\AgentCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentCommissionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        $query = AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->with(['member']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $commissions = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'commissions' => AgentCommissionResource::collection($commissions),
        ]);
    }
}

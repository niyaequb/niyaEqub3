<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\RegisteredVia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\StoreMemberRequest;
use App\Http\Resources\Api\MemberResource;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AgentMembersController extends Controller
{
    protected $smsService;
    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (!$agent) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Agent profile not found.',
                ],
                404,
            );
        }

        $query = $agent
            ->members()
            ->with(['user', 'agent'])
            ->withCount(['equbMemberships as active_equbs_count' => fn ($q) => $q->where('status', 'active')])
            ->latest();

        if ($request->filled('search')) {
            $term = '%'.trim($request->string('search')->toString()).'%';
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', $term)
                ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', $term)));
        }

        // Up to 500 per page so the app can show an agent's whole list.
        $perPage = max(1, min(500, $request->integer('per_page', 15)));
        $members = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'members' => MemberResource::collection($members),
        ]);
    }

    public function generateRandomPassword(): string
    {
        return str()->random(8);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (!$agent) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Agent profile not found.',
                ],
                404,
            );
        }

        $password = $this->generateRandomPassword();

        $member = DB::transaction(function () use ($request, $agent, $password) {
            $user = User::create([
                'name' => $request->input('full_name'),
                'phone' => $request->input('phone'),
                'password' => Hash::make($password),
                'type' => 'member',
                // Registered in person by a trusted agent, who receives the
                // SMS credentials for them: they must be able to log in.
                'phone_verified_at' => now(),
                'is_active' => true,
            ]);

            $member = $user
                ->member()
                ->create([
                    'full_name' => $request->input('full_name'),
                    'gender' => $request->input('gender'),
                    'date_of_birth' => $request->input('date_of_birth'),
                    'address' => $request->input('address'),
                    'agent_id' => $agent->id,
                    'registered_via' => RegisteredVia::Agent,
                    'referral_code_used' => $agent->referral_code,
                    'registered_at' => now(),
                ])
                ->load(['user', 'agent']);

            // send welcome sms with credentials
            $appName = env('APP_NAME');
            $this->smsService->sendSms(
                $user->phone,
                "Your {$appName} account has been created successfully.\nUsername: {$user->phone}\nPassword: {$password}\nUse this credentials to access your account. Please don't forget to change your password. Thank you.",
            );

            return $member;
        });

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Member created successfully.',
                'member' => new MemberResource($member),
            ],
            201,
        );
    }
}

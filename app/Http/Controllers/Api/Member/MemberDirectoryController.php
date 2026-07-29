<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Finds people to invite into a group Equb.
 *
 * Deliberately narrow: an exact phone number or an exact referral code only.
 * There is no partial-name search, so the member base cannot be enumerated.
 */
class MemberDirectoryController extends Controller
{
    public function __construct(protected SmsService $smsService) {}

    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required_without:referral_code', 'nullable', 'string', 'max:20'],
            'referral_code' => ['required_without:phone', 'nullable', 'string', 'max:20'],
        ]);

        $key = 'member-lookup:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many lookups. Please wait a minute.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $query = Member::query()->with('user:id,phone,name,profile_picture');

        if ($request->filled('phone')) {
            $phone = $this->smsService->formatPhoneNumber($request->input('phone'));
            $query->whereHas('user', fn ($q) => $q->where('phone', $phone));
        } else {
            $code = strtoupper($request->input('referral_code'));
            $query->whereHas('user', fn ($q) => $q->where('referral_code', $code));
        }

        $member = $query->first();

        if (! $member) {
            return response()->json([
                'status' => 'success',
                'data' => null,
                'message' => 'Nobody on Niya uses that number yet. You can still invite them by SMS.',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'member_id' => $member->id,
                'name' => $member->full_name ?? $member->user?->name,
                'phone' => $member->user?->phone,
                'profile_picture_url' => $member->user?->profile_picture_url,
            ],
        ]);
    }
}

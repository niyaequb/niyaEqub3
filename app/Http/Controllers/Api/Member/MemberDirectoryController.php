<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Finds people to invite into a group Equb.
 *
 * `lookup()` is an exact phone / referral-code match.
 * `search()` backs the "add members" autocomplete: a partial match on name or
 * phone, with a hard result cap and rate limiting so the member base cannot be
 * walked, returning only the fields the picker needs.
 */
class MemberDirectoryController extends Controller
{
    /** Results are capped so a broad query cannot dump the member base. */
    protected const MAX_RESULTS = 20;

    public function __construct(protected SmsService $smsService) {}

    /**
     * GET|POST /member/member-search?q=...
     *
     * Runs from the first character so results appear while typing. Phone
     * matching works on the last 8 digits, so 09xxxxxxxx finds a member stored
     * as +2519xxxxxxxx and vice versa.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) ($request->input('q')
            ?? $request->input('query')
            ?? $request->input('search')
            ?? ''));

<<<<<<< HEAD
        if ($term === '') {
=======
        if (mb_strlen($term) < 1) {
>>>>>>> bde2286da060c83d6fecd3232b2e9f8149a3cf98
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $key = 'member-search:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many searches. Please wait a moment.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $myMemberId = $request->user()->member?->id;
        $digits = preg_replace('/\D+/', '', $term);

        try {
            $members = Member::query()
<<<<<<< HEAD
                ->with('user:id,name,phone')
                ->when($myMemberId, fn ($q) => $q->whereKeyNot($myMemberId))
                ->where(function ($query) use ($term, $digits) {
                    $query->where('full_name', 'like', "%{$term}%")
                        ->orWhereHas('user', function ($u) use ($term, $digits) {
                            $u->where('name', 'like', "%{$term}%");

                            // Match a partial number whatever form it is stored in.
                            if ($digits !== '') {
                                $tail = mb_substr($digits, -8);
                                $u->orWhere('phone', 'like', "%{$tail}%");
                            }
                        });
                })
                ->orderBy('full_name')
                ->limit(self::MAX_RESULTS)
=======
                ->with('user:id,phone,name')
                ->when($myMemberId, fn ($q) => $q->whereKeyNot($myMemberId))
                ->where(function ($q) use ($term) {
                    $q->whereHas('user', fn ($u) => $u
                        ->where('phone', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%"))
                        ->orWhere('full_name', 'like', "%{$term}%");
                })
                ->limit(20)
>>>>>>> bde2286da060c83d6fecd3232b2e9f8149a3cf98
                ->get();
        } catch (\Throwable $e) {
            Log::error('Member search failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Could not search members right now.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
<<<<<<< HEAD
            // No avatar URL here on purpose: that accessor hits the storage
=======
            // No avatar URL here on purpose: that accessor touches the storage
>>>>>>> bde2286da060c83d6fecd3232b2e9f8149a3cf98
            // disk once per row, which turns a type-ahead into a slow request.
            'data' => $members->map(fn (Member $m): array => [
                'member_id' => $m->id,
                'name' => $m->full_name ?: ($m->user?->name ?? 'Member'),
                'phone' => $m->user?->phone,
            ])->values(),
        ]);
    }

    /**
     * POST /member/member-lookup — exact phone or referral code.
     */
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

        $query = Member::query()->with('user:id,phone,name');

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
                'name' => $member->full_name ?: ($member->user?->name ?? 'Member'),
                'phone' => $member->user?->phone,
            ],
        ]);
    }
}

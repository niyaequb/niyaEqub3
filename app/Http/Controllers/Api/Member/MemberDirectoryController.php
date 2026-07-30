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
 * `lookup()` is an exact phone/referral-code match.
 * `search()` backs the "add members" autocomplete: partial match on name or
 * phone, but with a minimum query length, a hard result cap and rate limiting
 * so the member base cannot be walked, and only the fields the picker needs
 * are returned.
 */
class MemberDirectoryController extends Controller
{
    /** Shortest query we will run a partial search for. */
    protected const MIN_QUERY_LENGTH = 2;

    protected const MAX_RESULTS = 20;

    public function __construct(protected SmsService $smsService) {}

    /**
     * GET|POST /member/member-search?q=...
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) ($request->input('q')
            ?? $request->input('query')
            ?? $request->input('search')
            ?? ''));

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return response()->json([
                'status' => 'success',
                'data' => [],
                'message' => __('Type at least :n characters.', ['n' => self::MIN_QUERY_LENGTH]),
            ]);
        }

        $key = 'member-search:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 90)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many searches. Please wait a moment.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $myMemberId = $request->user()->member?->id;
        $digits = preg_replace('/\D+/', '', $term);

        $members = Member::query()
            ->with('user:id,name,phone,profile_picture')
            ->when($myMemberId, fn ($q) => $q->whereKeyNot($myMemberId))
            ->where(function ($query) use ($term, $digits) {
                $query->where('full_name', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($u) use ($term, $digits) {
                        $u->where('name', 'like', "%{$term}%");

                        // Match a partial number regardless of 09… / +2519… form.
                        if ($digits !== '') {
                            $tail = mb_substr($digits, -8);
                            $u->orWhere('phone', 'like', "%{$tail}%");
                        }
                    });
            })
            ->orderBy('full_name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $members->map(fn (Member $m): array => [
                'member_id' => $m->id,
                'name' => $m->full_name ?? $m->user?->name ?? 'Member',
                'phone' => $m->user?->phone,
                'profile_picture_url' => $m->user?->profile_picture_url,
            ])->values(),
        ]);
    }

    /**
     * POST /member/member-lookup — exact phone or referral code.
     */
    /**
     * Partial search by name or phone, for the app's "add members" field.
     *
     * Wider than lookup() on purpose — the autocomplete has to work before you
     * finish typing. Kept narrow enough to discourage scraping: at least two
     * characters, 20 results, rate limited per user.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) ($request->input('q')
            ?? $request->input('query')
            ?? $request->input('search')
            ?? ''));

        if (mb_strlen($term) < 2) {
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

        $members = Member::query()
            ->with('user:id,phone,name,profile_picture')
            ->when($myMemberId, fn ($q) => $q->whereKeyNot($myMemberId))
            ->where(function ($q) use ($term) {
                $q->whereHas('user', fn ($u) => $u
                    ->where('phone', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%"))
                    ->orWhere('full_name', 'like', "%{$term}%");
            })
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $members->map(fn (Member $m): array => [
                'member_id' => $m->id,
                'name' => $m->full_name ?? $m->user?->name ?? 'Member',
                'phone' => $m->user?->phone,
                'profile_picture_url' => $m->user?->profile_picture_url,
            ])->values(),
        ]);
    }

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

<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Finds people to add to a Group Equb.
 *
 * Deliberately dependency-free: no constructor injection and no cache-backed
 * rate limiter, because a failure in either takes the whole controller down
 * and every route on it returns 500 before any logic runs.
 *
 * The query mirrors the one used by the admin panel's "Add members" picker,
 * which is known to work against this schema.
 */
class MemberDirectoryController extends Controller
{
    /** Results are capped so a broad query cannot dump the member base. */
    protected const MAX_RESULTS = 20;

    /**
     * GET|POST /member/member-search?q=...
     *
     * Matches on member name, user name or phone. Phone matching uses the last
     * 8 digits so 09xxxxxxxx finds someone stored as +2519xxxxxxxx.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $term = trim((string) ($request->input('q')
                ?? $request->input('query')
                ?? $request->input('search')
                ?? ''));

            if ($term === '') {
                return response()->json(['status' => 'success', 'data' => []]);
            }

            $digits = preg_replace('/\D+/', '', $term);
            $tail = $digits !== '' ? substr($digits, -8) : null;

            $members = Member::query()
                ->with('user:id,name,phone')
                ->where(function ($query) use ($term, $tail) {
                    $query->where('full_name', 'like', "%{$term}%")
                        ->orWhereHas('user', function ($u) use ($term, $tail) {
                            $u->where('name', 'like', "%{$term}%");

                            if ($tail !== null) {
                                $u->orWhere('phone', 'like', "%{$tail}%");
                            }
                        });
                })
                ->limit(self::MAX_RESULTS)
                ->get();

            $myMemberId = $request->user()?->member?->id;

            $data = $members
                ->reject(fn (Member $m): bool => $myMemberId !== null && (int) $m->id === (int) $myMemberId)
                ->map(fn (Member $m): array => [
                    'member_id' => $m->id,
                    'name' => $m->full_name ?: ($m->user?->name ?? 'Member'),
                    'phone' => $m->user?->phone,
                ])
                ->values();

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('Member search failed: '.$e->getMessage(), [
                'query' => $request->input('q'),
                'exception' => $e,
            ]);

            // Never 500 a type-ahead: an empty list with a message is far less
            // disruptive than an error dialog on every keystroke.
            return response()->json([
                'status' => 'success',
                'data' => [],
                'message' => 'Search is unavailable right now.',
            ]);
        }
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

        try {
            $query = Member::query()->with('user:id,phone,name');

            if ($request->filled('phone')) {
                $phone = $this->normalisePhone((string) $request->input('phone'));
                $query->whereHas('user', fn ($q) => $q->where('phone', $phone));
            } else {
                $code = strtoupper((string) $request->input('referral_code'));
                $query->whereHas('user', fn ($q) => $q->where('referral_code', $code));
            }

            $member = $query->first();
        } catch (\Throwable $e) {
            Log::error('Member lookup failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'status' => 'error',
                'message' => 'Could not look that up right now.',
            ], 500);
        }

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

    /**
     * 09xxxxxxxx and 9xxxxxxxx both become +2519xxxxxxxx. Done here rather than
     * through SmsService so this controller has no constructor dependencies.
     */
    protected function normalisePhone(string $input): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $input) ?? '';

        if (str_starts_with($clean, '+251')) {
            return $clean;
        }

        if (str_starts_with($clean, '251')) {
            return '+'.$clean;
        }

        if (str_starts_with($clean, '0')) {
            return '+251'.substr($clean, 1);
        }

        if (strlen($clean) === 9 && (str_starts_with($clean, '9') || str_starts_with($clean, '7'))) {
            return '+251'.$clean;
        }

        return $clean;
    }
}

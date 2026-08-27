<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member directory lookups for "Add members".
 *
 * BOTH ENDPOINTS ARE DELIBERATELY INERT.
 *
 * They used to answer a partial name or phone fragment with a list of matching
 * members, each carrying a full name and a full phone number. Typing "bila"
 * returned every Bilal on the platform with their numbers attached, and typing
 * a two-digit fragment walked the member base a page at a time. Any signed-in
 * account could harvest the whole directory — names, numbers and who is
 * registered — with a few hundred requests.
 *
 * There is no safe way to serve a type-ahead over this data. Any endpoint that
 * answers "does an account exist for X" is an oracle, and a partial one is
 * simply a faster oracle. So the search was removed rather than rate-limited or
 * trimmed to fewer fields.
 *
 * WHAT REPLACES IT
 *
 * Nothing on the client. The app now takes a complete phone number, validates
 * the format locally, and sends it to the invite endpoint. Invitations already
 * resolve a number to an existing member server-side — see
 * MemberEqubGroupService::invite(), which looks the number up itself and
 * creates either a push invitation or an SMS one. The inviter never learns
 * which of the two happened, and never needed to.
 *
 * WHY THE ROUTES STILL EXIST
 *
 * Older installed apps still call them. Returning an empty result degrades
 * those builds to "nothing found", which is the behaviour we want anyway;
 * deleting the routes would show an error dialog on every keystroke instead.
 * Remove these once the old versions are out of circulation.
 */
class MemberDirectoryController extends Controller
{
    /**
     * GET|POST /member/member-search — retired.
     *
     * Always empty. See the class note: partial-match search over the member
     * directory is an enumeration hole, not a feature to be tuned.
     */
    public function search(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [],
            'message' => 'Enter a full phone number to invite someone.',
        ]);
    }

    /**
     * POST /member/member-lookup — retired.
     *
     * Confirming that a given number belongs to a registered member is the
     * same disclosure in single-record form: it turns a stolen contact list
     * into a list of confirmed Niya users. The invite flow does not need the
     * answer, so it is no longer given.
     */
    public function lookup(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => null,
            'message' => 'Enter a full phone number to invite someone.',
        ]);
    }
}

<?php

namespace App\Observers;

use App\Models\EqubJoinLog;
use App\Models\EqubMembership;
use Illuminate\Support\Facades\Log;

/**
 * Writes the permanent join log (equb_join_logs) for every place taken in an
 * Equb — through the apps, an agent or the admin panel alike, since all of
 * them create the membership through Eloquent.
 */
class EqubMembershipObserver
{
    public function created(EqubMembership $membership): void
    {
        // The log must never be the reason a member cannot join. A failure
        // here — the table not migrated yet, say — is logged and the join
        // goes ahead.
        try {
            EqubJoinLog::recordFor($membership);
        } catch (\Throwable $e) {
            Log::warning('Could not write the Equb join log', [
                'equb_membership_id' => $membership->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

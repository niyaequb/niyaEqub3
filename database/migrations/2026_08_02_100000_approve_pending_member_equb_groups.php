<?php

use App\Enums\EqubGroupModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Group Equbs no longer wait for an admin to approve them.
 *
 * Approval is now off by default (services.equb.group_requires_approval), so
 * new groups are created approved. This releases the ones already sitting in
 * pending — without it their owners would stay stuck on "awaiting approval"
 * with no way to invite anyone, since nothing will ever come along to approve
 * them.
 *
 * Only member-created groups are touched: platform Equbs have no owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('equb_groups')
            ->whereNotNull('owner_member_id')
            ->where('moderation_status', EqubGroupModerationStatus::Pending->value)
            ->update([
                'moderation_status' => EqubGroupModerationStatus::Approved->value,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deliberately not reversible. Rolling these back to pending would
        // freeze groups that members are already paying into, and we cannot
        // tell which ones were pending before this ran.
    }
};

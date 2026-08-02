<?php

use App\Enums\EqubGroupModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group Equbs no longer wait for an admin to approve them.
 *
 * Two things happen here:
 *
 * 1. The column default flips from 'pending' to 'approved', so any insert that
 *    does not name the column lands approved.
 * 2. Every member-created group already sitting in 'pending' is released.
 *    Without this their owners stay stuck on "awaiting approval" forever, since
 *    nothing will ever come along to approve them now that the queue is gone.
 *
 * Platform Equbs (owner_member_id is null) are left alone — moderation never
 * applied to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equb_groups', 'moderation_status')) {
            Schema::table('equb_groups', function (Blueprint $table): void {
                $table->string('moderation_status')
                    ->default(EqubGroupModerationStatus::Approved->value)
                    ->change();
            });
        }

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
        // The column default is reversible; the data change is not. Putting
        // live groups back to 'pending' would freeze Equbs that members are
        // already paying into, and there is no record of which ones were
        // pending beforehand.
        if (Schema::hasColumn('equb_groups', 'moderation_status')) {
            Schema::table('equb_groups', function (Blueprint $table): void {
                $table->string('moderation_status')
                    ->default(EqubGroupModerationStatus::Pending->value)
                    ->change();
            });
        }
    }
};

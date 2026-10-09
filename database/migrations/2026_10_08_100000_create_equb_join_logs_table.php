<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who joined which Equb, and when — kept for good.
 *
 * WHY A SEPARATE TABLE RATHER THAN READING equb_memberships
 *
 * A membership row is working state. It is cancelled, it is deleted with its
 * group, and test data is wiped out of it wholesale before go-live. Each of
 * those also wipes the only record that someone ever joined. This table is the
 * record: one row written the moment a place is taken (EqubMembershipObserver),
 * never edited, never deleted by the app.
 *
 * So everything an admin needs is COPIED in, not referenced: the person's
 * name and phone, the Equb's and package's names, the amount and the cycle,
 * as they were at that moment. The ids are kept beside them for following a
 * row back while the original still exists, and deliberately carry NO foreign
 * keys — a key would either block deleting the membership or delete this row
 * with it, and this row must survive both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_join_logs', function (Blueprint $table) {
            $table->id();

            // --- Who -------------------------------------------------------
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('member_name')->nullable();
            $table->string('member_phone', 32)->nullable()->index();

            // A place held for someone without a Niya account ("responsibility
            // people") is taken, and paid for, by a sponsor.
            $table->unsignedBigInteger('sponsor_member_id')->nullable();
            $table->string('sponsor_name')->nullable();
            $table->string('sponsor_phone', 32)->nullable();

            // --- Which Equb --------------------------------------------------
            $table->unsignedBigInteger('equb_membership_id')->nullable()->index();
            $table->unsignedBigInteger('equb_group_id')->nullable()->index();
            $table->string('equb_name')->nullable();
            $table->unsignedBigInteger('equb_package_id')->nullable()->index();
            $table->string('package_name')->nullable();
            // A member-created Group (Family) Equb rather than a Niya plan.
            $table->boolean('is_group_equb')->default(false);
            $table->decimal('contribution_amount', 14, 2)->nullable();
            $table->unsignedInteger('contribution_frequency_days')->nullable();
            $table->string('role', 30)->nullable();

            // --- How and when ------------------------------------------------
            // mobile_app | mini_app | agent | admin | system
            $table->string('source', 20)->nullable()->index();
            $table->timestamp('joined_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_join_logs');
    }
};

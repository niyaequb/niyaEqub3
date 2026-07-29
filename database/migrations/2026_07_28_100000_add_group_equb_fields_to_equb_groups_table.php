<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            // --- Ownership -------------------------------------------------
            // NULL  = platform group (created by admin, public, current behaviour)
            // set   = member-created private group ("Group Equb")
            $table->foreignId('owner_member_id')
                ->nullable()
                ->after('equb_package_id')
                ->constrained('members')
                ->nullOnDelete();

            $table->string('visibility')->default('public')->after('name');                 // public | private
            $table->string('moderation_status')->default('approved')->after('visibility');  // pending | approved | rejected
            $table->string('invite_code', 12)->nullable()->unique()->after('moderation_status');
            $table->text('description')->nullable()->after('invite_code');
            $table->boolean('allow_member_invites')->default(true)->after('description');

            // --- Winner group configuration --------------------------------
            // single       -> 1 winner per round (existing platform behaviour, default)
            // fixed_size   -> exactly N winners every round
            // random_split -> system splits the group into winner sub-groups (7 -> 3 + 4)
            // manual       -> owner/admin picks the winner group each round
            $table->string('winner_selection_mode')->default('single')->after('draw_type');
            $table->unsignedInteger('winners_per_draw')->nullable()->after('winner_selection_mode');
            $table->unsignedInteger('min_winners_per_draw')->nullable()->after('winners_per_draw');
            $table->unsignedInteger('max_winners_per_draw')->nullable()->after('min_winners_per_draw');

            // Frozen plan, e.g. [3, 4] for 7 members. Generated when the group starts.
            $table->json('winner_split_plan')->nullable()->after('max_winners_per_draw');
            $table->unsignedInteger('split_plan_cursor')->default(0)->after('winner_split_plan');

            // If set, every winner receives this exact amount instead of pot / winners.
            $table->decimal('payout_per_winner', 12, 2)->nullable()->after('total_amount_per_draw');

            // Private groups play for real money between friends: only members who are
            // current on their contributions may be drawn.
            $table->boolean('draw_requires_up_to_date')->default(false)->after('payout_per_winner');

            // --- Moderation trail ------------------------------------------
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->index(['visibility', 'status']);
            $table->index('owner_member_id', 'equb_groups_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->dropForeign(['owner_member_id']);
            $table->dropForeign(['approved_by_admin_id']);
            $table->dropIndex(['visibility', 'status']);
            $table->dropIndex('equb_groups_owner_idx');
            $table->dropColumn([
                'owner_member_id', 'visibility', 'moderation_status', 'invite_code',
                'description', 'allow_member_invites', 'winner_selection_mode',
                'winners_per_draw', 'min_winners_per_draw', 'max_winners_per_draw',
                'winner_split_plan', 'split_plan_cursor', 'payout_per_winner',
                'draw_requires_up_to_date', 'approved_at', 'approved_by_admin_id',
                'rejection_reason',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Group Equb participates in a running platform Equb Group (e.g. "Raha
     * Daily"). The parent supplies the contribution per person, the frequency
     * and the schedule, so nothing about money is typed in by hand.
     *
     * Draws are then run at the parent level: the admin picks a target member
     * count and the system selects whole Group Equbs as the winners.
     */
    public function up(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->foreignId('parent_equb_group_id')
                ->nullable()
                ->after('owner_member_id')
                ->constrained('equb_groups')
                ->nullOnDelete();

            // Set when this Group Equb is drawn as a winner, so it drops out of
            // the pool for later rounds.
            $table->boolean('has_won_round')->default(false)->after('parent_equb_group_id');
            $table->timestamp('won_round_at')->nullable()->after('has_won_round');

            $table->index(['parent_equb_group_id', 'has_won_round'], 'equb_groups_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->dropIndex('equb_groups_parent_idx');
            $table->dropForeign(['parent_equb_group_id']);
            $table->dropColumn(['parent_equb_group_id', 'has_won_round', 'won_round_at']);
        });
    }
};

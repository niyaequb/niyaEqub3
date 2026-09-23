<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hand brake on a single place in the draw.
 *
 * Arrears are handled by the schedule and need no column — a member two
 * rounds behind is removed from the pool automatically and comes back the
 * moment they pay. This is for the cases the schedule cannot see: a suspected
 * duplicate account, a disputed payment, a place under investigation.
 *
 * It is dated rather than boolean so a block expires on its own. A permanent
 * flag set during one investigation is a place quietly excluded from every
 * future round because nobody remembered to clear it.
 *
 * Nothing here touches payment history. Taking a place out of the draw and
 * editing what somebody paid are different acts, and a system that does the
 * second to achieve the first has destroyed its own audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->timestamp('draw_blocked_until')->nullable()->after('last_overdue_notified_at');
            $table->string('draw_block_reason')->nullable()->after('draw_blocked_until');
            $table->timestamp('fraud_flagged_at')->nullable()->after('draw_block_reason');
        });
    }

    public function down(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->dropColumn(['draw_blocked_until', 'draw_block_reason', 'fraud_flagged_at']);
        });
    }
};

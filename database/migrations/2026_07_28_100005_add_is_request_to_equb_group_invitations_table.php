<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The same table carries both directions of joining a group:
     *
     *   is_request = false  the owner invited someone (they accept or decline)
     *   is_request = true   someone used an invite code and is asking to join
     *                       (the owner approves or rejects)
     */
    public function up(): void
    {
        Schema::table('equb_group_invitations', function (Blueprint $table) {
            $table->boolean('is_request')->default(false)->after('status');
            $table->index(['equb_group_id', 'is_request', 'status'], 'equb_invites_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equb_group_invitations', function (Blueprint $table) {
            $table->dropIndex('equb_invites_direction_idx');
            $table->dropColumn('is_request');
        });
    }
};

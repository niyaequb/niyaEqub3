<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->string('role')->default('member')->after('member_id'); // owner | member
            $table->foreignId('invited_by_member_id')
                ->nullable()
                ->after('role')
                ->constrained('members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->dropForeign(['invited_by_member_id']);
            $table->dropColumn(['role', 'invited_by_member_id']);
        });
    }
};

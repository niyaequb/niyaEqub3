<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('phone');
        });

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN type ENUM('admin', 'staff', 'agent', 'member') NOT NULL DEFAULT 'staff'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN type ENUM('staff') NOT NULL DEFAULT 'staff'"
        );
    }
};

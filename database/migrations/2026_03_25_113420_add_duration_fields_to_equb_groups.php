<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->renameColumn('duration_days', 'duration_value');
            $table->string('duration_unit')->nullable()->after('duration_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->renameColumn('duration_value', 'duration_days');
            $table->dropColumn('duration_unit');
        });
    }
};

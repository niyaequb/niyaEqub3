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
        Schema::table('equb_memberships', function (Blueprint $table) {
            $table->foreignId('cohort_id')->nullable()->constrained('cohorts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            //
            
        });
    }
};

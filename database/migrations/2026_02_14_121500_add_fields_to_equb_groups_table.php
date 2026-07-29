<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->string('name')->after('equb_package_id');
            $table->decimal('fixed_contribution_amount', 12, 2)->nullable()->after('name');
            $table->unsignedInteger('contribution_frequency_days')->nullable()->after('fixed_contribution_amount');
            $table->string('duration_type')->nullable()->default('fixed')->after('contribution_frequency_days');
            $table->unsignedInteger('duration_days')->nullable()->after('duration_type');
        });
    }

    public function down(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'fixed_contribution_amount',
                'contribution_frequency_days',
                'duration_type',
                'duration_days',
            ]);
        });
    }
};

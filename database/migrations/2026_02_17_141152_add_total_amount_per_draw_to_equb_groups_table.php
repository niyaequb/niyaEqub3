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
            $table->decimal('total_amount_per_draw', 12, 2)->nullable()->after('draw_type');
        });
    }

    public function down(): void
    {
        Schema::table('equb_groups', function (Blueprint $table) {
            $table->dropColumn('total_amount_per_draw');
        });
    }
};

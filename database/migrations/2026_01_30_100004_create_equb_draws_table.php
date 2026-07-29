<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_group_id')->constrained('equb_groups')->cascadeOnDelete();
            $table->timestamp('draw_date');
            $table->foreignId('executed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('winner_membership_id')->constrained('equb_memberships')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_draws');
    }
};

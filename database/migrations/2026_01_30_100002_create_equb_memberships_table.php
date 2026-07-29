<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_group_id')->constrained('equb_groups')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->decimal('contribution_amount', 12, 2);
            $table->unsignedInteger('contribution_frequency_days')->default(1);
            $table->timestamp('join_date');
            $table->timestamp('calculated_end_date')->nullable();
            $table->unsignedInteger('draw_position')->nullable();
            $table->boolean('has_won')->default(false);
            $table->timestamp('win_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['equb_group_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_memberships');
    }
};

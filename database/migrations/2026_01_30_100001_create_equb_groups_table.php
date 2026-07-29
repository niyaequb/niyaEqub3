<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_package_id')->constrained('equb_packages')->cascadeOnDelete();
            $table->timestamp('registration_open_at');
            $table->timestamp('registration_close_at')->nullable();
            $table->timestamp('equb_start_date')->nullable();
            $table->timestamp('equb_end_date')->nullable();
            $table->unsignedInteger('max_members')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('current_members_count')->default(0);
            $table->string('draw_type')->default('manual'); // manual, automatic, both
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_groups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // normal, flexible
            $table->decimal('fixed_contribution_amount', 12, 2)->nullable();
            $table->decimal('min_contribution_amount', 12, 2)->nullable();
            $table->decimal('max_contribution_amount', 12, 2)->nullable();
            $table->unsignedInteger('contribution_frequency_days')->default(1);
            $table->string('duration_type')->default('fixed'); // fixed, per_member
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('max_members')->nullable();
            $table->text('terms_content')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_packages');
    }
};

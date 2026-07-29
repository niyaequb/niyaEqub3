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
           // check if table already exists
        if (Schema::hasTable('sms_logs')) {
            return;
        }
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->text('message');
            $table->enum('status', ['success', 'error', 'pending'])->default('pending');
            $table->text('response')->nullable();
            $table->string('provider')->nullable(); // AFRO or GEEZ
            $table->string('reference')->nullable(); // Reference from SMS provider
            $table->nullableMorphs('sendable'); // Polymorphic relation (can be linked to any model, nullable for test SMS)
             $table->string('sendable_type')->nullable()->change();
            $table->unsignedBigInteger('sendable_id')->nullable()->change();
            $table->timestamps();

            $table->index('phone');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};

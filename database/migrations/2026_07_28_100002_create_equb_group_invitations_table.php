<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_group_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_group_id')->constrained('equb_groups')->cascadeOnDelete();
            $table->foreignId('invited_by_member_id')->constrained('members')->cascadeOnDelete();

            // Registered member invited from inside the app…
            $table->foreignId('member_id')->nullable()->constrained('members')->cascadeOnDelete();
            // …or a phone number that is not on the platform yet (invite by SMS).
            $table->string('phone')->nullable();

            $table->string('status')->default('pending'); // pending | accepted | declined | cancelled | expired
            $table->string('token', 40)->unique();
            $table->text('message')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['equb_group_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_group_invitations');
    }
};

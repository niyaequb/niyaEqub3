<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A draw can now produce a *winner group* (several memberships at once).
        // equb_draws.winner_membership_id is kept and always points at the first
        // winner, so every existing query, API resource and screen keeps working.
        Schema::create('equb_draw_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_draw_id')->constrained('equb_draws')->cascadeOnDelete();
            $table->foreignId('equb_membership_id')->constrained('equb_memberships')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->decimal('amount_won', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['equb_draw_id', 'equb_membership_id'], 'draw_winner_unique');
        });

        Schema::table('equb_draws', function (Blueprint $table) {
            $table->unsignedInteger('round_number')->nullable()->after('draw_date');
            $table->unsignedInteger('winners_count')->default(1)->after('round_number');
            $table->string('mode')->default('automatic')->after('winners_count'); // automatic | manual
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equb_draws', function (Blueprint $table) {
            $table->dropColumn(['round_number', 'winners_count', 'mode', 'notes']);
        });

        Schema::dropIfExists('equb_draw_winners');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draw you can re-run.
 *
 * Until now a draw recorded who won and nothing about how. That is fine right
 * up to the first member who asks why somebody else was picked — at which
 * point there is no answer, only an assurance.
 *
 * The seed is the whole trick. The winner is chosen by walking the weighted
 * pool against a number derived from it, so given the seed and the entries,
 * both stored here, anyone can recompute the result and get the same name.
 * The snapshot holds the entries: who was in, what each place weighed and
 * why, and who was excluded with the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_draws', function (Blueprint $table) {
            // Hex, generated per round from random_bytes. Never reused.
            $table->string('random_seed', 64)->nullable()->after('mode');

            $table->unsignedInteger('pool_size')->nullable()->after('random_seed');
            $table->unsignedInteger('excluded_count')->nullable()->after('pool_size');
            $table->decimal('total_weight', 14, 4)->nullable()->after('excluded_count');
            $table->decimal('winner_weight', 14, 4)->nullable()->after('total_weight');

            // The winner's own chance, as a percentage, at the moment of the
            // draw. Stored rather than recomputed because the pool changes
            // the instant the round ends and the figure would never be
            // reproducible from live data afterwards.
            $table->decimal('winner_odds', 7, 3)->nullable()->after('winner_weight');

            $table->json('eligibility_snapshot')->nullable()->after('winner_odds');
        });
    }

    public function down(): void
    {
        Schema::table('equb_draws', function (Blueprint $table) {
            $table->dropColumn([
                'random_seed',
                'pool_size',
                'excluded_count',
                'total_weight',
                'winner_weight',
                'winner_odds',
                'eligibility_snapshot',
            ]);
        });
    }
};

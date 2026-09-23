<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retries, and which desk a report belongs to.
 *
 * Two gaps are closed here.
 *
 * The first is that a failed job stayed failed. `attempts` was already being
 * counted, and nothing ever read it: markFailed() was terminal, so a printer
 * that was out of paper for five minutes lost the morning report for good and
 * the only way to get it back was for somebody to notice and press a button.
 * next_attempt_at makes a failure a delay instead of a loss — the job returns
 * to the queue on a backoff and gives up only after max_attempts.
 *
 * The second is routing. Every agent competed for every job, so a branch with a
 * thermal receipt printer would happily win the head office's A4 summary and
 * print forty pages of it two inches wide. target_station_id lets a schedule
 * name the desk it is meant for; a job with no target is still free for any
 * desk, which is the right default for a single-printer office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_print_jobs', function (Blueprint $table) {
            // Where it should print. Null means any enabled station.
            $table->foreignId('target_station_id')
                ->nullable()
                ->after('report_print_schedule_id')
                ->constrained('print_stations')
                ->nullOnDelete();

            // Where it actually printed. claimed_by stays as the human-readable
            // label for rows written before stations existed.
            $table->foreignId('print_station_id')
                ->nullable()
                ->after('target_station_id')
                ->constrained('print_stations')
                ->nullOnDelete();

            $table->unsignedTinyInteger('max_attempts')->default(3)->after('attempts');

            // The queue's real readiness test. A job that failed at 08:00 with
            // a backoff of five minutes is queued but not yet claimable, and
            // this is the column that says so.
            $table->timestamp('next_attempt_at')->nullable()->after('max_attempts');
            $table->timestamp('last_attempt_at')->nullable()->after('next_attempt_at');

            // Lower runs first. A test page an operator is standing over should
            // not queue behind a backlog of overnight reports.
            $table->unsignedSmallInteger('priority')->default(100)->after('source');

            // Whether the browser handed this to the spooler without showing a
            // dialog. Worth recording per job: a station can be silent-ready in
            // the morning and not after somebody relaunches the browser from
            // the Start menu, and this is where that shows up.
            $table->boolean('printed_silently')->default(false)->after('printed_at');

            // The claim query filters on status and readiness together.
            $table->index(['status', 'next_attempt_at']);
            $table->index(['target_station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('report_print_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropIndex(['target_station_id', 'status']);
            $table->dropConstrainedForeignId('target_station_id');
            $table->dropConstrainedForeignId('print_station_id');
            $table->dropColumn([
                'max_attempts',
                'next_attempt_at',
                'last_attempt_at',
                'priority',
                'printed_silently',
            ]);
        });
    }
};

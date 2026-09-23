<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which desk a schedule prints at, and whether anyone is watching it.
 *
 * `delivery = agent` used to mean "whichever browser tab happens to be open".
 * With more than one branch running an agent that is a coin toss, and the
 * report lands wherever the coin fell. target_station_id makes the answer part
 * of the schedule: the daily takings print at the desk the manager sits at.
 *
 * consecutive_failures exists because a schedule that has failed every morning
 * for a week is a different situation from one that failed once. The first
 * needs somebody told; the second fixes itself on the next run. Without a
 * counter the two look identical in last_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_print_schedules', function (Blueprint $table) {
            $table->foreignId('target_station_id')
                ->nullable()
                ->after('delivery')
                ->constrained('print_stations')
                ->nullOnDelete();

            $table->unsignedTinyInteger('consecutive_failures')->default(0)->after('last_error');

            // Set when the schedule is switched off by something other than a
            // person — a run that failed too many times in a row. Kept apart
            // from is_active so the page can say "we stopped this for you, and
            // here is why" rather than leaving an admin to wonder who did it.
            $table->timestamp('paused_at')->nullable()->after('consecutive_failures');
            $table->string('paused_reason', 500)->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('report_print_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_station_id');
            $table->dropColumn(['consecutive_failures', 'paused_at', 'paused_reason']);
        });
    }
};

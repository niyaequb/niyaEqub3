<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the printer connection type to schedules.
     *
     * The original design assumed every printer had an IP address, which is
     * wrong for the common case: a USB printer plugged into the machine
     * running the app. `printer_connection` records how to reach it, and
     * `printer_name` holds a system printer name or a \\PC\Share path — for
     * which a host and port are meaningless.
     */
    public function up(): void
    {
        Schema::table('report_print_schedules', function (Blueprint $table) {
            $table->string('printer_connection')
                ->default('system')
                ->after('delivery');

            $table->string('printer_name')
                ->nullable()
                ->after('printer_connection');
        });

        // Existing rows were created when only network printing existed, so
        // preserve their behaviour rather than silently repointing them at a
        // local spooler.
        Schema::getConnection()
            ->table('report_print_schedules')
            ->whereNotNull('printer_host')
            ->update(['printer_connection' => Schema::getConnection()->raw('printer_protocol')]);
    }

    public function down(): void
    {
        Schema::table('report_print_schedules', function (Blueprint $table) {
            $table->dropColumn(['printer_connection', 'printer_name']);
        });
    }
};

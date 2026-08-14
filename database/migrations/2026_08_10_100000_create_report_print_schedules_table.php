<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_print_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Which report to build. `period` is the reporting window (daily /
            // weekly / monthly); `filters` is the saved filter set from the
            // report page, so a schedule can be narrowed to one Equb group.
            $table->string('period')->default('daily');
            $table->json('filters')->nullable();

            // When to build it. Kept separate from `period` on purpose: a
            // monthly report is often printed weekly so the office can watch
            // the month accumulate.
            $table->string('frequency')->default('daily'); // daily, weekly, monthly
            $table->string('run_at', 5)->default('08:00'); // HH:MM, local to `timezone`
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 1 (Mon) - 7 (Sun)
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-31, clamped to month length
            $table->string('timezone')->default('Africa/Addis_Ababa');

            // Where the finished report goes.
            $table->string('delivery')->default('agent'); // agent, network, none
            $table->string('format')->default('pdf');     // pdf, html, escpos
            $table->string('paper')->default('a4');       // a4, a5, thermal80, thermal58
            $table->unsignedTinyInteger('copies')->default(1);

            // Direct network printer settings. Only used when delivery=network.
            $table->string('printer_host')->nullable();
            $table->unsignedInteger('printer_port')->nullable();
            $table->string('printer_protocol')->default('raw'); // raw (JetDirect 9100), ipp
            $table->string('printer_queue')->nullable();        // IPP queue/printer name

            $table->boolean('is_active')->default(true);

            // Run bookkeeping. next_run_at is what the scheduler polls, so it
            // is indexed and always written even on failure — otherwise a
            // broken printer would make the command retry every minute.
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_print_schedules');
    }
};

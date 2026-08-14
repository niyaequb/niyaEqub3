<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_print_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_print_schedule_id')
                ->nullable()
                ->constrained('report_print_schedules')
                ->nullOnDelete();

            $table->string('source')->default('manual'); // manual, schedule
            $table->string('status')->default('queued'); // queued, printing, printed, failed, cancelled
            $table->string('title');

            // A snapshot of what was asked for. Stored rather than re-derived
            // so a reprint six months later reproduces the original figures
            // even if the schedule has since been edited.
            $table->string('period')->default('daily');
            $table->json('filters')->nullable();
            $table->json('summary')->nullable(); // headline totals, for the queue list

            $table->string('format')->default('pdf');
            $table->string('paper')->default('a4');
            $table->unsignedTinyInteger('copies')->default(1);
            $table->string('delivery')->default('agent');

            // Rendered artefact on disk, relative to the configured disk.
            $table->string('file_path')->nullable();
            $table->string('file_disk')->default('local');

            // Set when a print agent picks the job up, so two open browser
            // tabs cannot print the same report twice.
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by')->nullable();

            $table->timestamp('printed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['delivery', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_print_jobs');
    }
};

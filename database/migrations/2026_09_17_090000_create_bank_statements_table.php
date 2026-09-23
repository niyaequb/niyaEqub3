<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A statement file as the bank sent it.
 *
 * WHY WE KEEP THE FILE AND NOT JUST THE NUMBERS
 *
 * Reconciliation is an argument with a bank, and an argument needs evidence.
 * When a line does not match anything on our side, the useful answer is "here
 * is the row, in the file you sent us on the 3rd, with this transaction id" —
 * not "our system says there is a discrepancy". So the import is recorded as
 * an event: which file, who loaded it, how many rows it had and what they
 * added up to, and the column mapping that was used to read it.
 *
 * The totals are stored rather than recomputed because they are a claim about
 * the file at the moment it was read. If a later import of the same period
 * disagrees, that difference is itself the finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();

            // Which bank. Matches the gateway slug in config/payments.php, so
            // a statement can only ever be matched against payments taken
            // through the same bank.
            $table->string('gateway', 40)->index();

            $table->string('original_filename');
            $table->string('file_path')->nullable();
            $table->string('file_disk', 40)->default('local');
            $table->string('file_hash', 64)->nullable();

            // The window the rows actually cover, read from the data rather
            // than from the filename — a file named "September" that stops on
            // the 12th has been truncated, and that is worth seeing.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->decimal('total_credit', 16, 2)->default(0);
            $table->decimal('total_debit', 16, 2)->default(0);

            /*
             * How the file's columns were read.
             *
             * Stored per import because banks change their exports without
             * telling anyone, and a statement read six months ago under a
             * different layout must still be explainable. Also what makes the
             * importer reusable: the mapping is remembered per bank and
             * offered as the default next time.
             */
            $table->json('column_map')->nullable();

            $table->string('status', 20)->default('imported');
            $table->text('notes')->nullable();

            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['gateway', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day's books, closed.
 *
 * WHY A DAY IS A ROW RATHER THAN A QUERY
 *
 * The figures for a past day can always be recomputed, and for a while that
 * seems like a reason not to store them. It is the opposite. Reconciliation is
 * the act of someone saying "I have checked the 3rd and it balances" — and
 * that statement has to survive the numbers moving afterwards. A late
 * settlement, a corrected amount or a re-imported statement all change what a
 * query would return for the 3rd, and none of them should quietly rewrite what
 * was signed off.
 *
 * So each day gets a row holding what the figures were when it was checked,
 * who checked it, and what they said about the difference. If the recomputed
 * figures later disagree with the stored ones, that gap is a finding in its
 * own right rather than an invisible edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_days', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->string('gateway', 40);

            // --- Our side --------------------------------------------------
            $table->unsignedInteger('our_count')->default(0);
            $table->decimal('our_amount', 16, 2)->default(0);

            // Credited on somebody's word rather than the bank's. The figure
            // an auditor asks about first, so it is a column and not a filter.
            $table->unsignedInteger('unverified_count')->default(0);
            $table->decimal('unverified_amount', 16, 2)->default(0);

            // --- The bank's side -------------------------------------------
            $table->unsignedInteger('bank_count')->default(0);
            $table->decimal('bank_amount', 16, 2)->default(0);

            // --- Where they meet -------------------------------------------
            $table->unsignedInteger('matched_count')->default(0);
            $table->decimal('matched_amount', 16, 2)->default(0);

            // Money the bank has that nobody was credited for, and money we
            // credited that the bank has no record of. Kept apart because they
            // are different problems with different people to call.
            $table->unsignedInteger('unmatched_bank_count')->default(0);
            $table->decimal('unmatched_bank_amount', 16, 2)->default(0);
            $table->unsignedInteger('unmatched_ours_count')->default(0);
            $table->decimal('unmatched_ours_amount', 16, 2)->default(0);

            $table->decimal('variance', 16, 2)->default(0);

            $table->string('status', 20)->default('open'); // open | balanced | variance | signed_off
            $table->boolean('statement_loaded')->default(false);

            $table->text('note')->nullable();
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            // One row per bank per day. A second import of the same day
            // updates the row rather than adding a rival version of the truth.
            $table->unique(['business_date', 'gateway']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_days');
    }
};

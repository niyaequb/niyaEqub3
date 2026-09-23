<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One credit on the bank's statement, and what we believe it paid for.
 *
 * THE ROW THAT MATTERS MOST HAS NO MATCH
 *
 * Most of these will pair off against a contribution and never be looked at
 * again. The valuable ones are the leftovers, in both directions:
 *
 *   a line with no payment   money is in our account and nobody was credited
 *                            for it. A member paid and is still being chased.
 *
 *   a payment with no line   we credited somebody for money the bank has no
 *                            record of. Either the statement is incomplete or
 *                            we are short.
 *
 * Neither is visible from our own records alone, which is the entire reason
 * for importing a statement rather than trusting the payments table.
 *
 * `external_ref` is the bank's transaction id and is unique per bank, which is
 * what makes re-importing an overlapping file safe: the same credit cannot be
 * counted twice however many times the file is loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40)->index();

            // --- What the bank said ---------------------------------------
            $table->string('external_ref')->nullable();   // their transaction id
            $table->string('bank_reference')->nullable(); // FT / core-banking ref
            $table->string('merchant_reference')->nullable(); // our order id, when echoed
            $table->timestamp('posted_at')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('direction', 8)->default('credit'); // credit | debit
            $table->string('currency', 8)->default('ETB');
            $table->string('payer_name')->nullable();
            $table->string('payer_account')->nullable();
            $table->string('payer_phone', 32)->nullable();
            $table->text('narrative')->nullable();

            // The row exactly as it was read, so a mapping mistake can be
            // diagnosed without going back to the file.
            $table->json('raw')->nullable();

            // --- What we made of it ---------------------------------------
            $table->string('match_status', 24)->default('unmatched');
            $table->foreignId('equb_payment_id')->nullable()->constrained('equb_payments')->nullOnDelete();

            // How the match was made and how sure we are. A match on the
            // bank's own transaction id is a fact; a match on amount and date
            // is a guess, and the two must not look the same in a list an
            // auditor is reading.
            $table->string('match_rule', 32)->nullable();
            $table->unsignedTinyInteger('match_confidence')->nullable();
            $table->string('match_note')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One credit, once, however often the file is re-imported.
            $table->unique(['gateway', 'external_ref'], 'bank_line_unique_txn');

            $table->index(['gateway', 'match_status']);
            $table->index(['gateway', 'posted_at']);
            $table->index('amount');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
